export type Lead = {
  id: number; name: string; phone: string; source: string; status: string; lost_reason: string | null;
  course: { id: number; title: string } | null; group: { id: number; name: string } | null; owner: { id: number; name: string } | null;
  next_contact_on: string | null; trial: { session_id: number; group_id: number; group_name: string | null; starts_at: string; cancelled: boolean } | null;
  comment: string | null; notes: string | null; student_id: number | null; converted_at: string | null; telegram: boolean; created_at: string; updated_at: string;
};
export type LeadActivity = { id: number; type: string; text: string | null; data: Record<string, unknown> | null; user: string | null; created_at: string };
export type LeadList = { data: Lead[]; meta: { total: number; page: number; pages: number }; counts: Record<string, number>; due_today: number };
export type LeadOptions = { data: { id: number; name: string }[]; courses: { id: number; title: string }[] };
export type FunnelRow = { total: number; contacted: number; trial: number; enrolled: number; lost: number; conversion: number | null };
export type LeadStats = {
  from: string; to: string; total: FunnelRow;
  by_source: (FunnelRow & { source: string })[]; by_course: (FunnelRow & { course_id: number | null; course_title: string | null })[];
  lost_reasons: { reason: string | null; count: number }[];
};

export const LEAD_STATUSES = ["NEW", "CONTACTED", "TRIAL", "ENROLLED", "LOST"] as const;
export const OPEN_STATUSES = ["NEW", "CONTACTED", "TRIAL"];
export const LEAD_TONE: Record<string, "info" | "accent" | "warning" | "success" | "danger"> = { NEW: "info", CONTACTED: "accent", TRIAL: "warning", ENROLLED: "success", LOST: "danger" };
export const LEAD_STATUS_FALLBACK: Record<string, string> = { NEW: "Yangi", CONTACTED: "Bog'lanildi", TRIAL: "Sinov darsi", ENROLLED: "Yozildi", LOST: "Rad etdi" };
