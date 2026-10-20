import { cookies } from "next/headers";
import { API_URL, TOKEN_COOKIE } from "@/lib/api";

/** Fayl yuklab olish (CSV va h.k.) — javob o'zgartirilmasdan uzatiladi. Faqat GET va faqat /v1/reports/*. */
export async function GET(req: Request, ctx: { params: Promise<{ path: string[] }> }) {
  const { path } = await ctx.params;
  if (path[0] !== "reports") return new Response("Not found", { status: 404 });
  const token = (await cookies()).get(TOKEN_COOKIE)?.value;
  if (!token) return new Response("Unauthorized", { status: 401 });
  const url = new URL(req.url);
  const res = await fetch(`${API_URL}/v1/${path.map(encodeURIComponent).join("/")}${url.search}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "text/csv,application/json" },
    cache: "no-store",
  });
  const headers = new Headers({ "Cache-Control": "no-store" });
  for (const h of ["content-type", "content-disposition"]) {
    const v = res.headers.get(h);
    if (v) headers.set(h, v);
  }
  return new Response(res.body, { status: res.status, headers });
}
