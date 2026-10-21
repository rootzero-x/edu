/**
 * Fayl yuklash yo'llari (multipart; backend UploadTickets::PATHS bilan bir xil).
 * Fayl Vercel orqali emas (4,5 MB chegara), bir martalik chipta bilan bevosita API ga yuboriladi.
 */
export const UPLOAD_PATHS = [
  /^lessons\/\d+\/materials\/upload$/,
  /^assignments\/\d+\/files$/,
  /^assignments\/\d+\/submission\/files$/,
];

export function uploadAllowed(path: string) {
  return UPLOAD_PATHS.some((re) => re.test(path));
}

/** Brauzerdagi tezkor tekshiruv (server baribir fayl ichidan tekshiradi). */
export function precheck(file: File, maxMb: number, allowed: string[]): string | null {
  const ext = file.name.includes(".") ? file.name.split(".").pop()!.toLowerCase() : "";
  if (allowed.length && !allowed.includes(ext)) return `Bu turdagi fayl qabul qilinmaydi. Ruxsat etilgan: ${allowed.join(", ")}.`;
  if (file.size > maxMb * 1024 * 1024) return `Fayl hajmi ${maxMb} MB dan oshmasligi kerak.`;
  if (file.size === 0) return "Fayl bo'sh.";
  return null;
}

export function fmtSize(bytes: number) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  if (bytes < 1024 ** 3) return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
  return `${(bytes / 1024 ** 3).toFixed(2)} GB`;
}

export type StoredFile = { id: number; name: string; mime: string; size: number; created_at?: string | null };

export type UploadTicket = { url: string; token: string };

/** Chipta bo'yicha faylni bevosita API ga yuborish (web va Mini App uchun umumiy). */
export async function sendWithTicket(ticket: UploadTicket, form: FormData): Promise<{ ok: boolean; status: number; data: any }> {
  let res: Response;
  try {
    res = await fetch(ticket.url, {
      method: "POST", headers: { Accept: "application/json", Authorization: `Bearer ${ticket.token}` }, body: form,
      cache: "no-store", credentials: "omit", signal: AbortSignal.timeout(300000),
    });
  } catch {
    return { ok: false, status: 0, data: { message: "Tarmoq bilan aloqa yo'q yoki yuklash juda uzoq davom etdi." } };
  }
  const data = await res.json().catch(() => ({ message: res.status === 413 ? "Fayl juda katta." : "Xatolik yuz berdi." }));
  return { ok: res.ok, status: res.status, data };
}
