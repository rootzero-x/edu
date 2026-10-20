import { NextResponse } from "next/server";

/** Multipart so'rovni API'ga oqim sifatida uzatish (fayl xotiraga to'liq o'qilmaydi). */
export async function forwardUpload(url: string, token: string, req: Request) {
  try {
    const res = await fetch(url, {
      method: "POST",
      headers: { Authorization: `Bearer ${token}`, Accept: "application/json", "Content-Type": req.headers.get("content-type") ?? "" },
      body: req.body,
      cache: "no-store",
      signal: AbortSignal.timeout(120000),
      // Node fetch: oqimli body uchun majburiy.
      duplex: "half",
    } as RequestInit & { duplex: "half" });
    const data = await res.json().catch(() => ({ message: res.status === 413 ? "Fayl juda katta." : "Xatolik yuz berdi." }));
    return NextResponse.json(data, { status: res.status, headers: { "Cache-Control": "no-store" } });
  } catch {
    return NextResponse.json({ message: "API bilan aloqa yo'q." }, { status: 503 });
  }
}
