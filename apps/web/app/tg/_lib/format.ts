/** Sana/vaqt Mini App uchun (vaqt zonasi sozlamadan). */
export function dayKey(iso: string, tz: string) {
  return new Date(iso).toLocaleDateString("en-CA", { timeZone: tz });
}

export function time(iso: string, tz: string) {
  return new Date(iso).toLocaleTimeString("uz-UZ", { timeZone: tz, hour: "2-digit", minute: "2-digit", hour12: false });
}

/** dd.mm.yyyy (brauzer lokalidan qat'i nazar). */
export function date(iso: string, tz: string) {
  const [y, m, d] = dayKey(iso, tz).split("-");
  return `${d}.${m}.${y}`;
}

/** "2026-10-21" → hafta kuni indeksi 1..7 (Du..Ya). */
export function isoWeekday(day: string) {
  const d = new Date(`${day}T12:00:00Z`).getUTCDay();
  return d === 0 ? 7 : d;
}

export function todayKey(tz: string, plusDays = 0) {
  return new Date(Date.now() + plusDays * 86400000).toLocaleDateString("en-CA", { timeZone: tz });
}

export function personName(u: { first_name: string; last_name?: string | null }) {
  return [u.first_name, u.last_name].filter(Boolean).join(" ");
}

export function pct(n: number) {
  return `${Math.round(n * 10) / 10}%`;
}
