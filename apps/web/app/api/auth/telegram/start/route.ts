import { NextResponse } from "next/server";
import { API_URL } from "@/lib/api";

export async function POST(req: Request) {
  const res = await fetch(`${API_URL}/v1/auth/telegram/start`, { method: "POST", headers: { Accept: "application/json", "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" } });
  return NextResponse.json(await res.json().catch(() => ({})), { status: res.status });
}
