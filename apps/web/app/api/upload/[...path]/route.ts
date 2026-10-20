import { NextResponse } from "next/server";
import { cookies } from "next/headers";
import { API_URL, TOKEN_COOKIE } from "@/lib/api";
import { WEB_HEADER, safePath } from "@/lib/safe";
import { uploadAllowed } from "@/lib/upload";
import { forwardUpload } from "@/lib/upload-server";

/** Fayl yuklash: multipart so'rov API'ga o'zgartirilmasdan oqim sifatida uzatiladi (token — httpOnly cookie'da). */
export async function POST(req: Request, ctx: { params: Promise<{ path: string[] }> }) {
  if (req.headers.get(WEB_HEADER) !== "1") return NextResponse.json({ message: "Ruxsat yo'q." }, { status: 403 });
  const { path } = await ctx.params;
  const safe = safePath(path);
  if (!safe || !uploadAllowed(safe)) return NextResponse.json({ message: "Topilmadi." }, { status: 404 });
  const token = (await cookies()).get(TOKEN_COOKIE)?.value;
  if (!token) return NextResponse.json({ message: "Avval tizimga kiring." }, { status: 401 });
  return forwardUpload(`${API_URL}/v1/${safe}`, token, req);
}
