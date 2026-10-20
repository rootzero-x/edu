import type { Tone } from "@/components/ui";

/** Davomat holatlari ranglari. Nomlar sozlamadan (attendance.status_labels). */
export const STATUS_TONE: Record<string, Tone> = { PRESENT: "success", LATE: "warning", ABSENT: "danger", EXCUSED: "info" };
export const STATUS_COLOR: Record<string, string> = { PRESENT: "var(--success)", LATE: "var(--warning-mid)", ABSENT: "var(--danger)", EXCUSED: "var(--info)" };
