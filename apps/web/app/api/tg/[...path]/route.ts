import { NextResponse } from "next/server";
import { TG_HEADER, tgApi } from "@/lib/tg-server";
import { safePath } from "@/lib/safe";

/** Mini App → Next → API. Token Mini App cookie'sida (httpOnly). Maxsus header CSRF'dan himoya qiladi. */
async function handle(req: Request, ctx: { params: Promise<{ path: string[] }> }) {
  if (req.headers.get(TG_HEADER) !== "1") return NextResponse.json({ message: "Forbidden" }, { status: 403 });
  const { path } = await ctx.params;
  const safe = safePath(path);
  if (!safe) return NextResponse.json({ message: "Topilmadi." }, { status: 404 });
  const url = new URL(req.url);
  const body = req.method === "GET" || req.method === "HEAD" ? undefined : await req.text();
  const { status, data } = await tgApi(`/v1/${safe}${url.search}`, { method: req.method, body });
  return NextResponse.json(data, { status });
}

export { handle as GET, handle as POST, handle as PUT, handle as PATCH, handle as DELETE };
