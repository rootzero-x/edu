import { MONTHS, fmtMoney } from "./format";
import { obj, str, type Settings } from "./settings";

/** To'lovlar jurnali yozuvi (API: BillingEntry::toApi). amount ishorali: hisoblash < 0, to'lov > 0. */
export type BillingEntry = {
  id: number;
  student_id: number;
  group_id: number | null;
  group: string | null;
  type: "CHARGE" | "PAYMENT" | "ADJUSTMENT";
  amount: number;
  entry_date: string;
  method: string | null;
  note: string | null;
  created_at: string | null;
  voided: boolean;
  voided_at: string | null;
  void_reason: string | null;
};

export type BillingSummary = {
  student: { id: number; first_name: string; last_name: string | null; phone: string };
  balance: number;
  debt: number;
  month_charged: number;
  last_payment: { amount: number; date: string; method: string | null } | null;
  recent: BillingEntry[];
};

export type BillingMe = { enabled: boolean; students: BillingSummary[] };

export type StudentBilling = BillingSummary & {
  groups: { id: number; name: string; active: boolean; joined_at: string; daily_charge: number }[];
  monthly_fee: number;
  totals: { charged: number; paid: number; adjusted: number };
  months: { month: string; charged: number; paid: number; adjusted: number }[];
  entries: BillingEntry[];
  meta: { total: number; page: number; pages: number };
  can_manage: boolean;
};

/** Sozlamalardagi nomlar va valyuta bilan formatlash (matnlar kodda emas — billing.* sozlamalarida). */
export function billingLabels(s: Settings) {
  const currency = str(s, "billing.currency", "so'm");
  return {
    currency,
    money: (n: number) => (n < 0 ? "−" : "") + fmtMoney(Math.abs(n), currency),
    signed: (n: number) => (n > 0 ? "+" : n < 0 ? "−" : "") + fmtMoney(Math.abs(n), currency),
    // Faqat sozlamadagi usullar (server ham shularni qabul qiladi); sozlama bo'sh bo'lsagina standartlar.
    methods: (Object.keys(obj<Record<string, string>>(s, "billing.method_labels")).length
      ? obj<Record<string, string>>(s, "billing.method_labels") : { CASH: "Naqd", CARD: "Karta", TRANSFER: "O'tkazma" }) as Record<string, string>,
    types: { CHARGE: "Hisoblandi", PAYMENT: "To'lov", ADJUSTMENT: "Tuzatish", ...obj<Record<string, string>>(s, "billing.type_labels") } as Record<string, string>,
  };
}

/** Balans holati: qarz / ortiqcha / nol. */
export const balanceTone = (n: number) => (n < 0 ? "danger" : n > 0 ? "success" : "neutral") as "danger" | "success" | "neutral";

/** "2026-10" → "oktabr 2026" */
export function monthLabel(ym: string) {
  const [y, m] = ym.split("-").map(Number);
  return `${MONTHS[(m ?? 1) - 1] ?? ym} ${y}`;
}

/** Sana sayt vaqt zonasida (YYYY-MM-DD). */
export const isoDateIn = (d: Date, tz: string) =>
  new Intl.DateTimeFormat("en-CA", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit" }).format(d);

/** "2026-10-20" → "20.10.2026" (vaqt zonasiz sana — Date'ga aylantirilmaydi). */
export const fmtYmd = (ymd: string) => ymd.slice(0, 10).split("-").reverse().join(".");
