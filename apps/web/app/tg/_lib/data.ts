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

/**
 * Guruhlar darslari vaqt bo'yicha bitta ro'yxatda. Bitta so'rov (GET /sessions) — har guruhga alohida
 * so'rov ko'p guruhli adminni api limitiga tushirardi.
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
    tg<{ data: Session[] }>("GET", `sessions?from=${from}&to=${to}&group_ids=${key}`).then((r) => {
      if (cancelled) return;
      if (!r.ok) return setState({ data: null, error: true });
      const list = r.data.data.flatMap((s) => (byId.has(s.group_id) ? [{ ...s, group: byId.get(s.group_id)! }] : []));
      setState({ data: list.sort((a, b) => a.starts_at.localeCompare(b.starts_at)), error: false });
    });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, from, to, attempt]);
  return { ...state, reload: () => setAttempt((n) => n + 1) };
}
