import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { API_URL } from "@/lib/api";
import { TG_HEADER, TG_TOKEN_COOKIE, TG_UID_COOKIE, tgApi, tgCookieOptions } from "@/lib/tg-server";

/** initData'dagi Telegram user id (imzo backend'da tekshiriladi; bu yerda faqat sessiyani qayta ishlatish uchun). */
function telegramUserId(initData: string): string | null {
  try {
    const user = JSON.parse(new URLSearchParams(initData).get("user") ?? "null");
    return user?.id ? String(user.id) : null;
  } catch {
    return null;
  }
}

export async function POST(req: Request) {
  if (req.headers.get(TG_HEADER) !== "1") return NextResponse.json({ message: "Forbidden" }, { status: 403 });
  const { init_data } = (await req.json().catch(() => ({}))) as { init_data?: string };
  if (typeof init_data !== "string" || !init_data) return NextResponse.json({ status: "invalid" }, { status: 422 });

  // Shu Telegram akkaunti uchun amaldagi token bo'lsa — yangisini yaratmaymiz.
  const jar = await cookies();
  const uid = telegramUserId(init_data);
  const existing = jar.get(TG_TOKEN_COOKIE)?.value;
  if (existing && uid && jar.get(TG_UID_COOKIE)?.value === uid) {
    const me = await tgApi<{ user: unknown }>("/v1/auth/me", {}, existing);
    if (me.status === 200) return NextResponse.json({ status: "ok", user: me.data.user });
  }

  const res = await fetch(`${API_URL}/v1/auth/telegram/miniapp`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json", "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
    body: JSON.stringify({ init_data }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || data.status !== "ok") {
    const out = NextResponse.json({ status: data.status ?? (res.status === 403 ? "blocked" : "invalid"), message: data.message }, { status: res.status === 200 ? 400 : res.status });
    out.cookies.set(TG_TOKEN_COOKIE, "", { ...tgCookieOptions, maxAge: 0 });
    return out;
  }
  const out = NextResponse.json({ status: "ok", user: data.user });
  out.cookies.set(TG_TOKEN_COOKIE, data.token, tgCookieOptions);
  if (uid) out.cookies.set(TG_UID_COOKIE, uid, tgCookieOptions);
  return out;
}
