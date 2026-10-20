import { NextResponse } from "next/server";
import { API_URL, TOKEN_COOKIE, cookieOptions } from "@/lib/api";

export async function POST(req: Request) {
  const { code, poll_secret } = await req.json();
  const res = await fetch(`${API_URL}/v1/auth/telegram/poll`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json", "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
    body: JSON.stringify({ code, poll_secret }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) return NextResponse.json(data, { status: res.status });
  if (data.status !== "ok") return NextResponse.json({ status: data.status });
  const out = NextResponse.json({ status: "ok", user: data.user });
  out.cookies.set(TOKEN_COOKIE, data.token, cookieOptions);
  return out;
}
