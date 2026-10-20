import { NextResponse } from "next/server";
import { api } from "@/lib/api";

/** Brauzer → Next → API. Token faqat serverda (httpOnly cookie). */
async function handle(req: Request, ctx: { params: Promise<{ path: string[] }> }) {
  const { path } = await ctx.params;
  const url = new URL(req.url);
  const body = req.method === "GET" || req.method === "HEAD" ? undefined : await req.text();
  const { status, data } = await api(`/v1/${path.join("/")}${url.search}`, { method: req.method, body });
  return NextResponse.json(data, { status });
}

export { handle as GET, handle as POST, handle as PUT, handle as PATCH, handle as DELETE };
