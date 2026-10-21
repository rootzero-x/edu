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

/** Hostingda bitta so'rovda 2 MB gacha fayl qabul qilinadi — kattasi shu hajmdagi bo'laklarda yuboriladi. */
const CHUNK = 1536 * 1024;

const uploadId = () =>
  (typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}${Math.random().toString(36).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, "");

/**
 * Chipta bo'yicha faylni bevosita API ga yuborish (web va Mini App uchun umumiy). Katta fayl bo'laklarga bo'linadi:
 * har biri X-Upload-Id / X-Chunk-Index / X-Chunk-Total bilan, oxirgisida fayl nomi va qo'shimcha maydonlar.
 */
export async function sendWithTicket(ticket: UploadTicket, file: File, fields: Record<string, string> = {}): Promise<{ ok: boolean; status: number; data: any }> {
  const total = Math.max(1, Math.ceil(file.size / CHUNK));
  const id = uploadId();
  for (let i = 0; i < total; i++) {
    const form = new FormData();
    const headers: Record<string, string> = { Accept: "application/json", Authorization: `Bearer ${ticket.token}` };
    if (total === 1) {
      form.append("file", file);
    } else {
      form.append("chunk", file.slice(i * CHUNK, (i + 1) * CHUNK), "blob");
      Object.assign(headers, { "X-Upload-Id": id, "X-Chunk-Index": String(i), "X-Chunk-Total": String(total) });
    }
    if (i === total - 1) {
      if (total > 1) form.append("filename", file.name);
      Object.entries(fields).forEach(([k, v]) => form.append(k, v));
    }
    let res: Response;
    try {
      res = await fetch(ticket.url, { method: "POST", headers, body: form, cache: "no-store", credentials: "omit", signal: AbortSignal.timeout(120000) });
    } catch {
      return { ok: false, status: 0, data: { message: "Tarmoq bilan aloqa yo'q yoki yuklash juda uzoq davom etdi." } };
    }
    const data = await res.json().catch(() => ({ message: res.status === 413 ? "Fayl juda katta." : "Xatolik yuz berdi." }));
    if (i === total - 1 || !res.ok) return { ok: res.ok, status: res.status, data };
  }
  return { ok: false, status: 0, data: { message: "Xatolik yuz berdi." } };
}
