/** Proksi yo'llari: "." / ".." / bo'sh segmentlar va boshqaruv belgilari rad etiladi (yo'ldan chiqib ketmaslik uchun). */
export function safePath(segments: string[]): string | null {
  if (!segments.length) return null;
  for (const seg of segments) {
    if (!seg || seg === "." || seg === ".." || /[\\/\x00-\x1f]/.test(seg)) return null;
  }
  return segments.map(encodeURIComponent).join("/");
}

/** Login'dan keyingi yo'naltirish: faqat shu saytning ichki yo'li (open redirect'dan himoya). */
export function safeNext(v: string | null | undefined, fallback = "/dashboard"): string {
  if (!v || !v.startsWith("/") || v.startsWith("//") || /[\\\x00-\x1f]/.test(v)) return fallback;
  try {
    const u = new URL(v, "http://local.invalid");
    return u.origin === "http://local.invalid" ? u.pathname + u.search + u.hash : fallback;
  } catch {
    return fallback;
  }
}

/** Brauzerdan proksiga keladigan so'rovlar uchun maxsus sarlavha (CSRF himoyasi). */
export const WEB_HEADER = "x-tisu-web";
