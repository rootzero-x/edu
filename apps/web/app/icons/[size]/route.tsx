import { appIcon } from "@/lib/appIcon";

export const revalidate = 3600;

/** /icons/192, /icons/512, /icons/512-maskable — manifest uchun PNG ikonkalar. */
export async function GET(_req: Request, ctx: { params: Promise<{ size: string }> }) {
  const { size } = await ctx.params;
  const m = /^(192|512)(-maskable)?$/.exec(size);
  if (!m) return new Response("Not found", { status: 404 });
  return appIcon(Number(m[1]), Boolean(m[2]));
}
