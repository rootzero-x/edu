export type AttendanceStatus = "PRESENT" | "LATE" | "ABSENT" | "EXCUSED";

export interface AttendancePolicy {
  /** Shu daqiqagacha kechikish "PRESENT" hisoblanadi. */
  graceMinutes: number;
  /** Shundan ko'p kechikish "ABSENT" hisoblanadi. */
  absentAfterMinutes: number;
}

export const DEFAULT_ATTENDANCE_POLICY: AttendancePolicy = { graceMinutes: 5, absentAfterMinutes: 30 };

export function classifyArrival(
  sessionStart: Date,
  arrivedAt: Date | null,
  policy: AttendancePolicy = DEFAULT_ATTENDANCE_POLICY,
): AttendanceStatus {
  if (!arrivedAt) return "ABSENT";
  const lateMin = (arrivedAt.getTime() - sessionStart.getTime()) / 60_000;
  if (lateMin <= policy.graceMinutes) return "PRESENT";
  if (lateMin <= policy.absentAfterMinutes) return "LATE";
  return "ABSENT";
}

/** Davomat foizi: PRESENT va LATE keldi, EXCUSED hisobdan chiqariladi. */
export function attendanceRate(statuses: readonly AttendanceStatus[]): number {
  const counted = statuses.filter((s) => s !== "EXCUSED");
  if (counted.length === 0) return 100;
  const attended = counted.filter((s) => s === "PRESENT" || s === "LATE").length;
  return Math.round((attended / counted.length) * 1000) / 10;
}
