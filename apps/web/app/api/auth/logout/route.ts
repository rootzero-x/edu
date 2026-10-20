import { NextResponse } from "next/server";
import { api, TOKEN_COOKIE } from "@/lib/api";

export async function POST() {
  await api("/v1/auth/logout", { method: "POST" }).catch(() => null);
  const out = NextResponse.json({ ok: true });
  out.cookies.delete(TOKEN_COOKIE);
  return out;
}
