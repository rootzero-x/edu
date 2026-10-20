export const WEEKDAYS = ["", "Du", "Se", "Cho", "Pa", "Ju", "Sha", "Ya"];

export function fmtDateTime(iso: string, tz?: string) {
  return new Date(iso).toLocaleString("uz-UZ", { timeZone: tz, day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
}

export function fmtDate(iso: string, tz?: string) {
  return new Date(iso).toLocaleDateString("uz-UZ", { timeZone: tz, day: "2-digit", month: "2-digit", year: "numeric" });
}

export function fmtTime(iso: string, tz?: string) {
  return new Date(iso).toLocaleTimeString("uz-UZ", { timeZone: tz, hour: "2-digit", minute: "2-digit", hour12: false });
}

export function fmtWeekday(iso: string, tz?: string) {
  // Dushanba = 1 … Yakshanba = 7, WEEKDAYS bilan mos.
  const d = new Date(new Date(iso).toLocaleString("en-US", { timeZone: tz }));
  return WEEKDAYS[d.getDay() === 0 ? 7 : d.getDay()];
}

export function fmtMoney(n: number, currency = "") {
  return `${n.toLocaleString("uz-UZ").replace(/,/g, " ")}${currency ? ` ${currency}` : ""}`;
}

/** "2 daqiqa oldin" kabi nisbiy vaqt. */
export function fmtAgo(ts: number, now = Date.now()) {
  const s = Math.max(0, Math.round((now - ts) / 1000));
  if (s < 10) return "hozirgina";
  if (s < 60) return `${s} soniya oldin`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m} daqiqa oldin`;
  return `${Math.round(m / 60)} soat oldin`;
}

export function personName(u: { first_name: string; last_name?: string | null }) {
  return [u.first_name, u.last_name].filter(Boolean).join(" ");
}

export function scheduleText(list: { weekday: number; start_time: string; end_time?: string }[]) {
  return list.map((x) => `${WEEKDAYS[x.weekday]} ${x.start_time.slice(0, 5)}${x.end_time ? `–${x.end_time.slice(0, 5)}` : ""}`).join(", ");
}
