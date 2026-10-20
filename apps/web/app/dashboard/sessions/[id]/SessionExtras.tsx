"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { NotebookPen, Plus, Save, Star } from "lucide-react";
import { Badge, Button, Card, CardHeader, EmptyState, Input, cx, useToast } from "@/components/ui";
import AssignmentDialog from "@/components/homework/AssignmentDialog";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDateTime } from "@/lib/format";
import type { Assignment, Scale } from "@/lib/homework";
import { bool, timezone } from "@/lib/settings";
import s from "./sheet.module.css";

type MarkRow = { student_id: number; name: string; score: number | null; note: string | null };
type Marks = { enabled: boolean; scale: Scale; rows: MarkRow[]; can_edit: boolean };

/** Mashg'ulot sahifasi: o'quvchilarga baho (marks.session_marks) va shu mashg'ulotga berilgan uyga vazifa. */
export default function SessionExtras({ sessionId, groupId, cancelled }: { sessionId: number; groupId: number; cancelled: boolean }) {
  const st = useSiteSettings();
  return (
    <div className={s.extras}>
      {bool(st, "marks.session_marks", true) && !cancelled && <SessionMarks sessionId={sessionId} />}
      {bool(st, "homework.enabled", true) && <SessionHomework sessionId={sessionId} groupId={groupId} />}
    </div>
  );
}

function SessionMarks({ sessionId }: { sessionId: number }) {
  const toast = useToast();
  const [data, setData] = useState<Marks | null>(null);
  const [edits, setEdits] = useState<Record<number, { score: number | null; note: string }>>({});
  const [busy, setBusy] = useState(false);
  const load = useCallback(async () => {
    const r = await call<Marks>("GET", `sessions/${sessionId}/marks`);
    if (r.ok) setData(r.data);
  }, [sessionId]);
  useEffect(() => { void load(); }, [load]);
  const val = (r: MarkRow) => edits[r.student_id] ?? { score: r.score, note: r.note ?? "" };
  const dirty = useMemo(() => (data?.rows ?? []).filter((r) => edits[r.student_id] && (edits[r.student_id].score !== r.score || edits[r.student_id].note !== (r.note ?? ""))), [data, edits]);
  if (!data || !data.enabled || data.rows.length === 0) return null;
  if (!data.can_edit && data.rows.every((r) => r.score == null)) return null;
  const { min, max } = data.scale;
  const buttons = max - min <= 9;
  const set = (r: MarkRow, patch: Partial<{ score: number | null; note: string }>) => setEdits((x) => ({ ...x, [r.student_id]: { ...val(r), ...patch } }));

  async function save() {
    setBusy(true);
    const r = await call("PUT", `sessions/${sessionId}/marks`, { items: dirty.map((x) => ({ student_id: x.student_id, score: val(x).score, note: val(x).note.trim() || null })) });
    setBusy(false);
    if (!r.ok) return toast.error("Baholar saqlanmadi", errorText(r.data));
    toast.success("Baholar saqlandi");
    setEdits({});
    void load();
  }

  return (
    <Card>
      <CardHeader title="Baholar" description={`Shkala: ${min}–${max}`} actions={data.can_edit && <Button size="sm" icon={<Save />} loading={busy} disabled={!dirty.length} onClick={save}>Saqlash{dirty.length ? ` (${dirty.length})` : ""}</Button>} />
      <ul className={s.marks}>
        {data.rows.map((r) => {
          const v = val(r);
          return (
            <li key={r.student_id} className={cx(s.markRow, dirty.includes(r) && s.dirty)}>
              <strong>{r.name}</strong>
              {data.can_edit ? (
                <>
                  {buttons ? (
                    <div className={s.markSeg} role="radiogroup" aria-label={`${r.name}: baho`}>
                      {Array.from({ length: max - min + 1 }, (_, i) => min + i).map((n) => (
                        <button key={n} type="button" role="radio" aria-checked={v.score === n} className={s.markOpt} onClick={() => set(r, { score: v.score === n ? null : n })}>{n}</button>
                      ))}
                    </div>
                  ) : (
                    <Input size="sm" type="number" inputMode="numeric" min={min} max={max} aria-label={`${r.name}: baho`} value={v.score ?? ""}
                      onChange={(e) => set(r, { score: e.target.value === "" ? null : Math.max(min, Math.min(max, Number(e.target.value))) })} style={{ maxWidth: 100 }} />
                  )}
                  <Input size="sm" placeholder="Izoh" aria-label={`${r.name}: izoh`} value={v.note} maxLength={255} onChange={(e) => set(r, { note: e.target.value })} />
                </>
              ) : r.score != null ? <Badge tone="accent" icon={<Star size={12} aria-hidden />}>{r.score}/{max}{r.note ? ` · ${r.note}` : ""}</Badge> : <span className="subtle">—</span>}
            </li>
          );
        })}
      </ul>
    </Card>
  );
}

function SessionHomework({ sessionId, groupId }: { sessionId: number; groupId: number }) {
  const tz = timezone(useSiteSettings());
  const [list, setList] = useState<{ mode: string; data: Assignment[] } | null>(null);
  const [creating, setCreating] = useState(false);
  const load = useCallback(async () => {
    const r = await call<{ mode: string; data: Assignment[] }>("GET", `assignments?group_id=${groupId}&status=all&limit=100`);
    if (r.ok) setList({ mode: r.data.mode, data: r.data.data.filter((a) => a.session_id === sessionId) });
  }, [groupId, sessionId]);
  useEffect(() => { void load(); }, [load]);
  if (!list) return null;
  const teacher = list.mode === "teacher";
  if (!teacher && list.data.length === 0) return null;
  return (
    <Card>
      <CardHeader title="Uyga vazifa" actions={teacher && <Button size="sm" variant="soft" icon={<Plus />} onClick={() => setCreating(true)}>Vazifa berish</Button>} />
      {list.data.length === 0 ? <EmptyState compact icon={<NotebookPen />} title="Bu mashg'ulotga vazifa berilmagan" /> : (
        <ul className={s.marks}>
          {list.data.map((a) => (
            <li key={a.id} className={s.markRow}>
              <Link className="a" href={`/dashboard/homework/${a.id}`}>{a.title}</Link>
              <span className="subtle">{a.due_at ? `Muddat: ${fmtDateTime(a.due_at, tz)}` : "Muddatsiz"}</span>
              {a.stats && <Badge tone="info">{a.stats.submitted}/{a.stats.total} topshirdi</Badge>}
            </li>
          ))}
        </ul>
      )}
      {teacher && <AssignmentDialog open={creating} onClose={() => setCreating(false)} groupId={groupId} sessionId={sessionId} onSaved={() => void load()} />}
    </Card>
  );
}
