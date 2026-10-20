import { NextResponse } from "next/server";
import { API_URL } from "@/lib/api";

/**
 * Ariza formasi uchun zaxira yo'l (brauzer API'ga to'g'ridan-to'g'ri ulana olmasa — masalan, CORS sozlanmagan).
 * Asosiy yo'l — brauzerdan API'ga: shunda IP limiti haqiqiy mijoz IP'si bo'yicha ishlaydi.
 */
export async function POST(req: Request) {
  const body = await req.text();
  if (body.length > 8000) return NextResponse.json({ message: "So'rov juda katta." }, { status: 413 });
  try {
    const res = await fetch(`${API_URL}/v1/public/leads`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json", "X-Forwarded-For": req.headers.get("x-forwarded-for") ?? "" },
      body,
      cache: "no-store",
      signal: AbortSignal.timeout(10000),
    });
    const data = await res.json().catch(() => ({}));
    return NextResponse.json(data, { status: res.status, headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ message: "API bilan aloqa yo'q." }, { status: 503 });
  }
}
