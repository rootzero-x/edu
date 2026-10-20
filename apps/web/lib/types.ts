export type Summary = {
  role: string;
  generated_at: string;
  today: {
    id: number; group_id: number; group_name: string; course_title: string | null; teacher: string | null; room: string | null;
    topic: string | null; starts_at: string; ends_at: string; cancelled: boolean; marked_count: number; students_count: number; can_mark: boolean;
  }[];
  stats: {
    today_sessions: number; today_marked: number; attendance_rate: number | null; attendance_days: number;
    attendance_counts: Record<string, number>; courses: number | null; groups: number; students: number | null; unmarked_sessions: number | null;
  };
  /** Yangi admin uchun "Boshlash" ro'yxati (faqat foydalanuvchilarni boshqaruvchilarga). */
  setup?: { steps: { key: string; done: boolean; href: string }[]; done: number; total: number } | null;
};

export type Person = { id: number; first_name: string; last_name: string | null; phone?: string };
export type Schedule = { weekday: number; start_time: string; end_time: string };
export type GroupRow = {
  id: number; name: string; active: boolean; room: string | null; room_id?: number | null; students_count: number; start_date: string;
  course: { id?: number; title: string } | null; teacher: Person | null; schedules: Schedule[];
};
export type GroupDetail = GroupRow & { students: Person[] };
export type SessionRow = { id: number; starts_at: string; ends_at: string; cancelled: boolean; topic: string | null; marked_count: number };
export type ReportRow = { student_id: number; name: string; counts: Record<string, number>; rate: number; left?: boolean; left_at?: string | null };
