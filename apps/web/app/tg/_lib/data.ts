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

/** Guruhlar bo'yicha darslar (GET /groups/{id}/sessions) — bitta ro'yxatga, vaqt bo'yicha. */
export function useSessions(groups: Group[] | null, from: string, to: string) {
  const [state, setState] = useState<{ data: SessionWithGroup[] | null; error: boolean }>({ data: null, error: false });
  const key = groups?.map((g) => g.id).join(",") ?? "";
  useEffect(() => {
    if (!groups) return;
    let cancelled = false;
    Promise.all(groups.map(async (g) => {
      const r = await tg<{ data: Session[] }>("GET", `groups/${g.id}/sessions?from=${from}&to=${to}`);
      if (!r.ok) throw new Error(String(r.status));
      return r.data.data.map((s) => ({ ...s, group: g }));
    }))
      .then((lists) => !cancelled && setState({ data: lists.flat().sort((a, b) => a.starts_at.localeCompare(b.starts_at)), error: false }))
      .catch(() => !cancelled && setState({ data: null, error: true }));
    return () => {
      cancelled = true;
    };
  }, [key, from, to]);
  return state;
}
