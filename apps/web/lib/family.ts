/** O'quvchi yig'masi (GET /v1/me/children va /v1/users/{id}/profile → overview). */
export type ChildGroup = {
  id: number; name: string; room: string | null; course_id: number | null; course_title: string | null; teacher: string | null; joined_at: string;
  status: string; frozen_until: string | null; frozen_from: string | null;
  schedule: { weekday: number; start: string; end: string }[]; next_session: string | null;
};
export type ChildOverview = {
  id: number; first_name: string; last_name: string | null;
  groups: ChildGroup[];
  attendance: {
    days: number; rate: number | null; counts: Record<string, number>;
    recent: { starts_at: string; group_name: string; topic: string | null; status: string; note: string | null }[];
  };
  quizzes: { quiz_id: number; title: string; is_final: boolean; course_title: string | null; percent: number | null; score: number | null; max_score: number | null; passed: boolean; submitted_at: string }[];
  certificates: { code: string; course_title: string; issued_at: string }[];
  balance: { balance: number; debt: number; currency: string } | null;
};
