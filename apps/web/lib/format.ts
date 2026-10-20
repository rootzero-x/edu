/**
 * Sana/vaqt/son formatlash. Brauzerlarda uz-UZ lokali har doim ham bo'lmaydi (server va brauzer
 * natijasi farq qilib, hydration xatosi beradi), shuning uchun Intl'dan faqat raqamli qismlarni
 * (en-US — hamma joyda bor) olamiz, nomlarni esa o'zimiz qo'yamiz.
 */
export const WEEKDAYS = ["", "Du", "Se", "Cho", "Pa", "Ju", "Sha", "Ya"];
export const WEEKDAYS_LONG = ["", "dushanba", "seshanba", "chorshanba", "payshanba", "juma", "shanba", "yakshanba"];
export const MONTHS = ["yanvar", "fevral", "mart", "aprel", "may", "iyun", "iyul", "avgust", "sentabr", "oktabr", "noyabr", "dekabr"];

const WD: Record<string, number> = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };
const pad = (n: number) => String(n).padStart(2, "0");

/** Berilgan vaqt zonasidagi sana qismlari. */
export function dateParts(input: string | number | Date, tz?: string) {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone: tz, year: "numeric", month: "numeric", day: "numeric", hour: "numeric", minute: "numeric", weekday: "short", hourCycle: "h23",
  }).formatToParts(new Date(input));
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? "";
  return { year: +get("year"), month: +get("month"), day: +get("day"), hour: +get("hour") % 24, minute: +get("minute"), weekday: WD[get("weekday")] ?? 1 };
}

export function fmtDate(iso: string, tz?: string) {
  const d = dateParts(iso, tz);
  return `${pad(d.day)}.${pad(d.month)}.${d.year}`;
}

export function fmtTime(iso: string, tz?: string) {
  const d = dateParts(iso, tz);
  return `${pad(d.hour)}:${pad(d.minute)}`;
}

export function fmtDateTime(iso: string, tz?: string) {
  const d = dateParts(iso, tz);
  return `${pad(d.day)}.${pad(d.month)} ${pad(d.hour)}:${pad(d.minute)}`;
}

export function fmtWeekday(iso: string, tz?: string) {
  return WEEKDAYS[dateParts(iso, tz).weekday];
}

/** "seshanba, 20-oktabr" */
export function fmtLongDate(input: string | number | Date, tz?: string) {
  const d = dateParts(input, tz);
  return `${WEEKDAYS_LONG[d.weekday]}, ${d.day}-${MONTHS[d.month - 1]}`;
}

export function fmtMoney(n: number, currency = "") {
  const s = String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, " ");
  return `${s}${currency ? ` ${currency}` : ""}`;
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

/**
 * Telefonni +998XXXXXXXXX shakliga keltirish (backend App\Support\Phone bilan bir xil qoidalar):
 * "90 123 45 67", "8 90 …", "998 90 …", "+998 (90) …" → "+998901234567". Tanilmasa — kiritilgani o'zgarmaydi
 * (server xorijiy raqamlarni sozlamaga qarab tekshiradi).
 */
export function normalizePhone(raw: string): string {
  const v = raw.trim();
  let d = v.replace(/\D+/g, "");
  if (v.startsWith("00")) d = d.slice(2);
  if (d.length === 9) d = `998${d}`;
  else if (!v.startsWith("+") && d.length === 10 && d.startsWith("8")) d = `998${d.slice(1)}`;
  return /^998\d{9}$/.test(d) ? `+${d}` : v;
}

/** "+998901234567" → "+998 90 123 45 67" (faqat ko'rsatish uchun). */
export function fmtPhone(phone: string): string {
  const m = /^\+998(\d{2})(\d{3})(\d{2})(\d{2})$/.exec(phone);
  return m ? `+998 ${m[1]} ${m[2]} ${m[3]} ${m[4]}` : phone;
}
