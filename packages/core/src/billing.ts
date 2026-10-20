/** Barcha summalar so'mda, butun son (tiyin ishlatilmaydi). */
export interface Discount {
  kind: "PERCENT" | "FIXED";
  value: number;
}

export function applyDiscount(amount: number, discount: Discount | null): number {
  if (!discount) return amount;
  const off = discount.kind === "PERCENT" ? Math.round((amount * discount.value) / 100) : discount.value;
  return Math.max(0, amount - off);
}

/**
 * Oy o'rtasida qo'shilgan o'quvchi uchun oylik to'lov:
 * oyning qolgan darslari soniga proporsional.
 */
export function proratedMonthlyFee(monthlyFee: number, totalLessons: number, remainingLessons: number): number {
  if (totalLessons <= 0) return 0;
  const r = Math.min(Math.max(remainingLessons, 0), totalLessons);
  return Math.round((monthlyFee * r) / totalLessons);
}

export interface LedgerEntry {
  type: "INVOICE" | "PAYMENT" | "REFUND";
  amount: number;
}

/** Musbat natija — qarz, manfiy — oldindan to'langan. */
export function balance(entries: readonly LedgerEntry[]): number {
  return entries.reduce((b, e) => {
    if (e.type === "INVOICE") return b + e.amount;
    if (e.type === "PAYMENT") return b - e.amount;
    return b + e.amount; // REFUND qarzni qayta oshiradi
  }, 0);
}
