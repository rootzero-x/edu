export interface CertificateEligibilityInput {
  attendanceRate: number;
  finalQuizPassed: boolean;
  debt: number;
}

export const CERTIFICATE_MIN_ATTENDANCE = 75;

export type Eligibility = { ok: true } | { ok: false; reasons: ("LOW_ATTENDANCE" | "QUIZ_NOT_PASSED" | "HAS_DEBT")[] };

export function certificateEligibility(i: CertificateEligibilityInput): Eligibility {
  const reasons: ("LOW_ATTENDANCE" | "QUIZ_NOT_PASSED" | "HAS_DEBT")[] = [];
  if (i.attendanceRate < CERTIFICATE_MIN_ATTENDANCE) reasons.push("LOW_ATTENDANCE");
  if (!i.finalQuizPassed) reasons.push("QUIZ_NOT_PASSED");
  if (i.debt > 0) reasons.push("HAS_DEBT");
  return reasons.length ? { ok: false, reasons } : { ok: true };
}
