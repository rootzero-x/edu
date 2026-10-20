import { NextResponse } from "next/server";
import { api } from "@/lib/api";
import { WEB_HEADER, safePath } from "@/lib/safe";

/** Brauzer → Next → API. Token faqat serverda (httpOnly cookie). */
async function handle(req: Request, ctx: { params: Promise<{ path: string[] }> }) {
  // CSRF: faqat o'z frontendimiz (lib/client.ts) bu sarlavhani yuboradi; boshqa subdomenlardan kelgan formalar o'tmaydi.
  if (req.headers.get(WEB_HEADER) !== "1") return NextResponse.json({ message: "Ruxsat yo'q." }, { status: 403 });
  const { path } = await ctx.params;
  const safe = safePath(path);
  if (!safe) return NextResponse.json({ message: "Topilmadi." }, { status: 404 });
  const url = new URL(req.url);
  const body = req.method === "GET" || req.method === "HEAD" ? undefined : await req.text();
  const { status, data } = await api(`/v1/${safe}${url.search}`, { method: req.method, body });
  return NextResponse.json(data, { status, headers: { "Cache-Control": "no-store" } });
}

export { handle as GET, handle as POST, handle as PUT, handle as PATCH, handle as DELETE };
