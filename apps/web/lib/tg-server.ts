import { cookies } from "next/headers";
import { API_URL } from "@/lib/api";

/**
 * Telegram Mini App sessiyasi. Sayt cookie'sidan alohida: Telegram Web'da Mini App iframe ichida
 * ochiladi, shuning uchun SameSite=None + Partitioned kerak. Cookie faqat /api ostiga yuboriladi
 * (auth va /api/tg proxy); ikkalasi ham maxsus header talab qiladi — boshqa saytdan CSRF imkoni yo'q.
 */
export const TG_TOKEN_COOKIE = "tisu_tg_token";
export const TG_UID_COOKIE = "tisu_tg_uid";
export const TG_HEADER = "x-tisu-tg";

export const tgCookieOptions = {
  httpOnly: true,
  secure: true,
  sameSite: "none" as const,
  partitioned: true,
  path: "/api",
  maxAge: 60 * 60 * 24 * 30,
};

/** Mini App tokeni bilan API chaqiruvi. */
export async function tgApi<T>(path: string, init: RequestInit = {}, token?: string): Promise<{ status: number; data: T }> {
  const t = token ?? (await cookies()).get(TG_TOKEN_COOKIE)?.value;
  const res = await fetch(`${API_URL}${path}`, {
    ...init,
    cache: "no-store",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(t ? { Authorization: `Bearer ${t}` } : {}),
      ...(init.headers ?? {}),
    },
  });
  const data = (await res.json().catch(() => ({}))) as T;
  return { status: res.status, data };
}
