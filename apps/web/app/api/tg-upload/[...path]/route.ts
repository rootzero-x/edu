import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { API_URL } from "@/lib/api";
import { safePath } from "@/lib/safe";
import { TG_HEADER, TG_TOKEN_COOKIE } from "@/lib/tg-server";
import { uploadAllowed } from "@/lib/upload";
import { forwardUpload } from "@/lib/upload-server";

/** Mini App'dan fayl yuklash (token — Mini App cookie'sida, maxsus header CSRF'dan himoya qiladi). */
export async function POST(req: Request, ctx: { params: Promise<{ path: string[] }> }) {
  if (req.headers.get(TG_HEADER) !== "1") return NextResponse.json({ message: "Forbidden" }, { status: 403 });
  const { path } = await ctx.params;
  const safe = safePath(path);
  if (!safe || !uploadAllowed(safe)) return NextResponse.json({ message: "Topilmadi." }, { status: 404 });
  const token = (await cookies()).get(TG_TOKEN_COOKIE)?.value;
  if (!token) return NextResponse.json({ message: "Unauthorized" }, { status: 401 });
  return forwardUpload(`${API_URL}/v1/${safe}`, token, req);
}
