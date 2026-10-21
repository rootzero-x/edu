import { cookies } from "next/headers";
import { API_URL, TOKEN_COOKIE } from "@/lib/api";
import { safePath } from "@/lib/safe";

/** Fayl yuklab olish (CSV, XLSX) — javob o'zgartirilmasdan uzatiladi. Faqat GET va faqat /v1/reports/*, /v1/billing/*.csv, /v1/notifications/*.csv, import shablonlari. */
export async function GET(req: Request, ctx: { params: Promise<{ path: string[] }> }) {
  const { path } = await ctx.params;
  const safe = safePath(path);
  const allowed = path[0] === "reports" || (["billing", "notifications"].includes(path[0]) && path[path.length - 1].endsWith(".csv"))
    || (path[0] === "imports" && path[path.length - 1] === "template");
  if (!allowed || !safe) return new Response("Not found", { status: 404 });
  const token = (await cookies()).get(TOKEN_COOKIE)?.value;
  if (!token) return new Response("Unauthorized", { status: 401 });
  const url = new URL(req.url);
  const res = await fetch(`${API_URL}/v1/${safe}${url.search}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/json" },
    cache: "no-store",
  });
  // Xato (ruxsat yo'q, noto'g'ri sana oralig'i va h.k.) JSON ".csv" fayl bo'lib saqlanib qolmasin — tushunarli sahifa.
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    const msg = String((data as { message?: string })?.message || "Faylni tayyorlab bo'lmadi.").replace(/[<>&"]/g, "");
    const html = `<!doctype html><html lang="uz"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">`
      + `<title>Yuklab olinmadi</title><body style="font-family:system-ui,sans-serif;max-width:560px;margin:15vh auto;padding:0 16px;color:#1f2937">`
      + `<h1 style="font-size:1.3rem">Fayl yuklab olinmadi</h1><p>${msg}</p><p><a href="javascript:history.back()">Orqaga qaytish</a></p></body></html>`;
    return new Response(html, { status: res.status, headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" } });
  }
  const headers = new Headers({ "Cache-Control": "no-store" });
  for (const h of ["content-type", "content-disposition"]) {
    const v = res.headers.get(h);
    if (v) headers.set(h, v);
  }
  return new Response(res.body, { status: res.status, headers });
}
