/**
 * Ochiq sozlamalarni xavfsiz o'qish (server va brauzerda ishlaydi).
 * Kodda faqat zaxira (default) qiymatlar turadi — haqiqiy matn/rang/interval API'dan keladi.
 */
export type Settings = Record<string, unknown> | null | undefined;

export function str(s: Settings, key: string, fallback = ""): string {
  const v = s?.[key];
  return typeof v === "string" && v.trim() !== "" ? v : fallback;
}

export function num(s: Settings, key: string, fallback: number): number {
  const v = s?.[key];
  return typeof v === "number" && Number.isFinite(v) ? v : fallback;
}

export function bool(s: Settings, key: string, fallback = false): boolean {
  const v = s?.[key];
  return typeof v === "boolean" ? v : fallback;
}

export function list<T = Record<string, unknown>>(s: Settings, key: string): T[] {
  const v = s?.[key];
  return Array.isArray(v) ? (v as T[]) : [];
}

export function obj<T = Record<string, string>>(s: Settings, key: string): T {
  const v = s?.[key];
  return (v && typeof v === "object" && !Array.isArray(v) ? v : {}) as T;
}

export const siteName = (s: Settings) => str(s, "site.name", "TISU Edu");
export const timezone = (s: Settings) => str(s, "app.timezone", "Asia/Tashkent");
export const statusLabels = (s: Settings) => obj<Record<string, string>>(s, "attendance.status_labels");

/** Avtomatik yangilanish oralig'i (soniya). 0 — o'chirilgan. */
export const refreshSeconds = (s: Settings) => Math.max(0, num(s, "ui.refresh_seconds", 20));

/** #hex rang to'g'rimi va uning ustidagi matn uchun kontrast rang. */
export function accentColors(hex: string): { accent: string; fg: string } | null {
  const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(hex.trim());
  if (!m) return null;
  let h = m[1];
  if (h.length === 3) h = h.split("").map((c) => c + c).join("");
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
  const lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;
  return { accent: `#${h}`, fg: lum > 0.42 ? "#0b0f17" : "#ffffff" };
}
