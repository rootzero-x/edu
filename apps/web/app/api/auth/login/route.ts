import { NextResponse } from "next/server";
import { API_URL, TOKEN_COOKIE, cookieOptions } from "@/lib/api";

export async function POST(req: Request) {
  const body = await req.json();
  const res = await fetch(`${API_URL}/v1/auth/login`, {
    method: "POST",
    headers: { Accept: "application/json", "Content-Type": "application/json", "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
    body: JSON.stringify({ phone: body.phone, password: body.password, device: "web" }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) return NextResponse.json(data, { status: res.status });
  const out = NextResponse.json({ user: data.user });
  out.cookies.set(TOKEN_COOKIE, data.token, cookieOptions);
  return out;
}
