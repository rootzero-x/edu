/**
 * Fayl yuklash yo'llari (multipart). Faqat shu ro'yxatdagi API yo'llari proksidan o'tadi —
 * web (/api/upload) va Mini App (/api/tg-upload) uchun bir xil.
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
