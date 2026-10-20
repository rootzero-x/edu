export const WEEKDAYS = ["", "Du", "Se", "Cho", "Pa", "Ju", "Sha", "Ya"];

export function fmtDateTime(iso: string, tz?: string) {
  return new Date(iso).toLocaleString("uz-UZ", { timeZone: tz, day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" });
}

export function personName(u: { first_name: string; last_name?: string | null }) {
  return [u.first_name, u.last_name].filter(Boolean).join(" ");
}
