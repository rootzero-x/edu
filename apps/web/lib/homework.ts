import type { Tone } from "@/components/ui";
import type { StoredFile } from "./upload";

/** Uyga vazifa API turlari (GET /v1/assignments*). */
export type HwStatus = "PENDING" | "SUBMITTED" | "LATE" | "GRADED" | "MISSED";
export type Mine = {
  status: HwStatus; submitted_at: string | null; body: string | null; link_url: string | null; score: number | null;
  feedback: string | null; graded_at: string | null; can_submit: boolean; files: StoredFile[];
};
export type Stats = { total: number; submitted: number; graded: number; late: number };
export type Assignment = {
  id: number; group_id: number; group_name: string | null; course_title: string | null; session_id: number | null; session_starts_at: string | null;
  lesson_id: number | null; lesson_title: string | null; title: string; body: string | null; due_at: string | null; max_score: number;
  author: string | null; created_at: string | null; files: StoredFile[]; stats?: Stats; my?: Mine; can_manage?: boolean;
  submissions?: SubmissionRow[];
};
export type SubmissionRow = {
  student_id: number; name: string; left_group: boolean; status: HwStatus; submitted_at: string | null; body: string | null; link_url: string | null;
  score: number | null; feedback: string | null; graded_at: string | null; files: StoredFile[];
};
export type Scale = { min: number; max: number };
export type MarkRow = {
  type: "session" | "homework"; session_id?: number; assignment_id?: number; group_id: number; group_name: string; date: string;
  title: string | null; score: number; max: number; percent: number | null; note: string | null;
};

export const HW_TONE: Record<HwStatus, Tone> = { PENDING: "warning", SUBMITTED: "info", LATE: "warning", GRADED: "success", MISSED: "danger" };
export const HW_DEFAULT_LABELS: Record<HwStatus, string> = {
  PENDING: "Topshirilmagan", SUBMITTED: "Topshirildi", LATE: "Kechikib topshirildi", GRADED: "Baholandi", MISSED: "Muddati o'tgan",
};

/** "YYYY-MM-DDTHH:mm" (datetime-local) ↔ ISO, sayt vaqt zonasida (Toshkent: +05:00, yozgi vaqt yo'q). */
export function toLocalInput(iso: string | null, tz: string) {
  if (!iso) return "";
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23" })
    .formatToParts(new Date(iso));
  const g = (t: string) => parts.find((p) => p.type === t)?.value ?? "00";
  return `${g("year")}-${g("month")}-${g("day")}T${g("hour")}:${g("minute")}`;
}

/** datetime-local qiymatini sayt zonasidagi vaqt sifatida ISO ga (offset zonaning shu paytdagi siljishidan). */
export function fromLocalInput(v: string, tz: string): string | null {
  if (!v) return null;
  const asUtc = new Date(`${v}:00Z`);
  const local = new Date(asUtc.toLocaleString("en-US", { timeZone: tz }));
  const utc = new Date(asUtc.toLocaleString("en-US", { timeZone: "UTC" }));
  return new Date(asUtc.getTime() - (local.getTime() - utc.getTime())).toISOString();
}
