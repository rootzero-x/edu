import type { Tone } from "@/components/ui";
import { dateParts } from "./format";
import { list, obj, type Settings } from "./settings";

/** A'zolik holatlari: nomlar sozlamadan (enrollment.status_labels), kodda — zaxira. */
export const ENROLLMENT_FALLBACK: Record<string, string> = {
  ACTIVE: "O'qimoqda", FROZEN: "Muzlatilgan", LEFT: "Chiqib ketgan", GRADUATED: "Bitirgan", TRANSFERRED: "Boshqa guruhga o'tgan",
};
export const ENROLLMENT_TONE: Record<string, Tone> = { ACTIVE: "success", FROZEN: "info", LEFT: "danger", GRADUATED: "accent", TRANSFERRED: "warning" };

export const enrollmentLabels = (s: Settings) => ({ ...ENROLLMENT_FALLBACK, ...obj<Record<string, string>>(s, "enrollment.status_labels") });
export const leaveReasons = (s: Settings) => list<string>(s, "enrollment.leave_reasons");
export const freezeReasons = (s: Settings) => list<string>(s, "enrollment.freeze_reasons");
export const reasonRequired = (s: Settings) => s?.["enrollment.require_reason"] !== false;

/** Bugungi sana (YYYY-MM-DD) markaz vaqt zonasida — <input type="date"> uchun. */
export function todayIso(tz?: string, addDays = 0) {
  const d = dateParts(Date.now() + addDays * 86400000, tz);
  return `${d.year}-${String(d.month).padStart(2, "0")}-${String(d.day).padStart(2, "0")}`;
}

/** "2026-10-25" → "25.10.2026" */
export const fmtDay = (iso: string | null | undefined) => (iso ? iso.slice(0, 10).split("-").reverse().join(".") : "—");

export type MemberInfo = {
  id: number; first_name: string; last_name: string | null; phone?: string; joined_at: string;
  status: string; since?: string; until: string | null; reason: string | null; frozen_from: string | null; frozen_to: string | null;
};
export type FormerMember = {
  id: number; first_name: string; last_name: string | null; joined_at: string; left_on: string; status: string; reason: string | null;
  transfer_group: { id: number; name: string } | null;
};
export type TimelineRow = {
  id: number; group_id: number; group_name: string; course_title: string | null; status: string; starts_on: string; ends_on: string | null;
  outcome: string | null; reason: string | null; note: string | null; transfer_group: { id: number; name: string } | null; created_by: string | null;
};
