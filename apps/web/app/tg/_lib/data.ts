"use client";

import { useEffect, useState } from "react";
import { tg } from "./api";

export type Schedule = { weekday: number; start_time: string; end_time: string };
export type Group = {
  id: number; name: string; room: string | null; active: boolean; teacher_id: number; course_id: number;
  course: { id: number; title: string }; teacher: { id: number; first_name: string; last_name: string | null } | null;
  schedules: Schedule[]; students_count: number;
};
export type Session = { id: number; group_id: number; starts_at: string; ends_at: string; topic: string | null; cancelled: boolean; marked_count: number };
export type SessionWithGroup = Session & { group: Group };

/** GET /v1/calendar qatori (web taqvimi bilan bir xil manba). */
type CalRow = Session & { homework_count: number; room: string | null };

/**
 * Guruhlar bo'yicha mashg'ulotlar — web taqvimi bilan bir xil endpoint (GET /calendar), bitta so'rovda;
 * natija berilgan guruhlar bilan cheklanadi va vaqt bo'yicha tartiblanadi.
 */
export function useSessions(groups: Group[] | null, from: string, to: string) {
  const [state, setState] = useState<{ data: SessionWithGroup[] | null; error: boolean }>({ data: null, error: false });
  const key = groups?.map((g) => g.id).join(",") ?? "";
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    if (!groups) return;
    if (groups.length === 0) return setState({ data: [], error: false });
    let cancelled = false;
    const byId = new Map(groups.map((g) => [g.id, g]));
    if (byId.size === 0) {
      setState({ data: [], error: false });
      return;
    }
    tg<{ data: CalRow[] }>("GET", `calendar?from=${from}&to=${to}`)
      .then((r) => {
        if (cancelled) return;
        if (!r.ok) return setState({ data: null, error: true });
        const list = r.data.data
          .filter((x) => byId.has(x.group_id))
          .map((x) => ({ ...x, marked_count: x.marked_count ?? 0, group: byId.get(x.group_id)! }))
          .sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        setState({ data: list, error: false });
      })
      .catch(() => !cancelled && setState({ data: null, error: true }));
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, from, to, attempt]);
  return { ...state, reload: () => setAttempt((n) => n + 1) };
}
