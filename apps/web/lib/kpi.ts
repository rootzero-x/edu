/** GET /v1/reports/kpi javobi. */
export type KpiMonth = {
  month: string; partial: boolean; attendance_rate: number | null; attendance_marks: number; active_students: number; new_students: number; left_students: number;
  churn_percent: number | null; quiz_attempts: number; quiz_pass_rate: number | null; homework_expected: number; homework_submitted: number;
  homework_rate: number | null; homework_avg_percent: number | null; teacher_hours: number; sessions: number;
};
export type Kpi = {
  months: string[]; series: KpiMonth[];
  teachers: { teacher_id: number; name: string; hours: Record<string, number>; sessions: Record<string, number> }[];
  telegram_reach_percent: number | null; targets: { attendance: number; quiz_pass: number };
  money: null | { currency: string; series: { month: string; income: number; charged: number; adjustments: number }[]; debt_total: number; debtors: number };
};
export type KpiStudent = { student_id: number; name: string; phone: string | null; group_id: number; group: string; joined_at: string | null; left_at: string | null };
