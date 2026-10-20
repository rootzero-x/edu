/**
 * Kalendar sanalari "YYYY-MM-DD" kalitlari bilan (sayt vaqt zonasida). Hisob UTC tushida —
 * yozgi/qishki vaqt va brauzer zonasi natijaga ta'sir qilmaydi.
 */
import { MONTHS, dateParts } from "./format";

const pad = (n: number) => String(n).padStart(2, "0");
const toKey = (d: Date) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
const fromKey = (k: string) => new Date(Date.UTC(+k.slice(0, 4), +k.slice(5, 7) - 1, +k.slice(8, 10), 12));

export function todayKey(tz: string) {
  const d = dateParts(Date.now(), tz);
  return `${d.year}-${pad(d.month)}-${pad(d.day)}`;
}

/** ISO vaqtning shu zonadagi kuni. */
export function dayOf(iso: string, tz: string) {
  const d = dateParts(iso, tz);
  return `${d.year}-${pad(d.month)}-${pad(d.day)}`;
}

/** Kun boshidan daqiqalar (zonada). */
export function minutesOf(iso: string, tz: string) {
  const d = dateParts(iso, tz);
  return d.hour * 60 + d.minute;
}

export function addDays(key: string, n: number) {
  const d = fromKey(key);
  d.setUTCDate(d.getUTCDate() + n);
  return toKey(d);
}

/** 1 = dushanba … 7 = yakshanba. */
export function isoWeekday(key: string) {
  return ((fromKey(key).getUTCDay() + 6) % 7) + 1;
}

export function startOfWeek(key: string) {
  return addDays(key, 1 - isoWeekday(key));
}

export function addMonths(key: string, n: number) {
  const d = fromKey(key.slice(0, 8) + "01");
  d.setUTCMonth(d.getUTCMonth() + n);
  return toKey(d);
}

/** Oy jadvali: oyning 1-kunini o'z ichiga olgan haftadan oxirgi kuni bor haftagacha (dushanbadan). */
export function monthGrid(key: string) {
  const first = key.slice(0, 8) + "01";
  const last = addDays(addMonths(first, 1), -1);
  const from = startOfWeek(first);
  const to = addDays(startOfWeek(last), 6);
  const days: string[] = [];
  for (let k = from; k <= to; k = addDays(k, 1)) days.push(k);
  return { from, to, days, month: key.slice(0, 7) };
}

export function weekDays(key: string) {
  const from = startOfWeek(key);
  return Array.from({ length: 7 }, (_, i) => addDays(from, i));
}

/** "20–26 oktabr 2026" yoki "29 sentabr – 5 oktabr 2026". */
export function weekTitle(from: string, to: string) {
  const [fy, fm, fd] = [+from.slice(0, 4), +from.slice(5, 7), +from.slice(8, 10)];
  const [ty, tm, td] = [+to.slice(0, 4), +to.slice(5, 7), +to.slice(8, 10)];
  if (fm === tm && fy === ty) return `${fd}–${td} ${MONTHS[fm - 1]} ${fy}`;
  return `${fd} ${MONTHS[fm - 1]}${fy !== ty ? ` ${fy}` : ""} – ${td} ${MONTHS[tm - 1]} ${ty}`;
}

export function monthTitle(key: string) {
  const m = MONTHS[+key.slice(5, 7) - 1];
  return `${m.charAt(0).toUpperCase()}${m.slice(1)} ${key.slice(0, 4)}`;
}

export function dayNum(key: string) {
  return +key.slice(8, 10);
}
