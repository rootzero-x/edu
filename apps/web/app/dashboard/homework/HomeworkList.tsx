"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { AlertTriangle, CalendarClock, NotebookPen, Paperclip, Plus, Star, UsersRound } from "lucide-react";
import { Badge, Button, Card, EmptyState, Field, PageHeader, Progress, Select, StatCard, Tabs } from "@/components/ui";
import AssignmentDialog from "@/components/homework/AssignmentDialog";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDate, fmtDateTime } from "@/lib/format";
import { HW_DEFAULT_LABELS, HW_TONE, type Assignment, type MarkRow } from "@/lib/homework";
import { obj, timezone } from "@/lib/settings";
import s from "./homework.module.css";

type ListResp = { mode: "teacher" | "student"; data: Assignment[] };

export default function HomeworkList({ initialTab, initialGroup }: { initialTab: string; initialGroup: string }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const labels = { ...HW_DEFAULT_LABELS, ...obj<Record<string, string>>(st, "homework.status_labels") };
  const [tab, setTab] = useState(initialTab);
  const [group, setGroup] = useState(initialGroup);
  const [list, setList] = useState<ListResp | null>(null);
  const [marks, setMarks] = useState<{ data: MarkRow[]; average_percent: number | null } | null>(null);
  const [groups, setGroups] = useState<{ id: number; name: string; active: boolean }[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const load = useCallback(async () => {
    const qs = new URLSearchParams({ status: tab === "marks" ? "all" : tab, ...(group ? { group_id: group } : {}) });
    const r = await call<ListResp>("GET", `assignments?${qs}`);
    if (!r.ok) { setError(errorText(r.data)); return; }
    setError(null);
    setList(r.data);
  }, [tab, group]);
  useEffect(() => { void load(); }, [load]);
  useEffect(() => { call<{ data: { id: number; name: string; active: boolean }[] }>("GET", "groups").then((r) => r.ok && setGroups(r.data.data.filter((g) => g.active))); }, []);
  useEffect(() => {
    if (tab !== "marks" || list?.mode !== "student") return;
    call<{ data: MarkRow[]; average_percent: number | null }>("GET", `marks/me${group ? `?group_id=${group}` : ""}`).then((r) => r.ok && setMarks(r.data));
  }, [tab, list?.mode, group]);

  const teacher = list?.mode === "teacher";
  const now = Date.now();

  return (
    <>
      <PageHeader
        title="Uyga vazifa"
        description={teacher ? "Berilgan vazifalar, topshirganlar va baholar" : "Vazifalaringiz, muddatlar va baholaringiz"}
        actions={teacher && <Button icon={<Plus />} onClick={() => setCreating(true)}>Vazifa berish</Button>}
      />
      <Tabs label="Bo'limlar" value={tab} onChange={setTab} items={[
        { id: "open", label: teacher ? "Joriy" : "Topshirilmagan" },
        { id: "all", label: "Hammasi" },
        ...(teacher || !list ? [] : [{ id: "marks", label: "Baholarim" }]),
      ]} />
      {groups.length > 1 && (
        <div className={s.filters}>
          <Field label="Guruh"><Select size="sm" value={group} onChange={(e) => setGroup(e.target.value)}><option value="">Barcha guruhlar</option>{groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}</Select></Field>
        </div>
      )}

      {error ? <Card><EmptyState icon={<AlertTriangle />} title="Yuklanmadi">{error}</EmptyState></Card>
        : !list ? <Card padded><p className="subtle" style={{ margin: 0 }}>Yuklanmoqda…</p></Card>
        : tab === "marks" ? <Marks marks={marks} tz={tz} />
        : list.data.length === 0 ? (
          <Card><EmptyState icon={<NotebookPen />} title={teacher ? "Vazifa yo'q" : tab === "open" ? "Topshirilmagan vazifa yo'q 🎉" : "Vazifa yo'q"}
            action={teacher && <Button icon={<Plus />} onClick={() => setCreating(true)}>Vazifa berish</Button>}>
            {teacher ? "Guruhingizga birinchi uyga vazifani bering." : undefined}
          </EmptyState></Card>
        ) : (
          <ul className={s.cards}>
            {list.data.map((a) => {
              const overdue = a.due_at && new Date(a.due_at).getTime() < now;
              return (
                <li key={a.id}>
                  <Link href={`/dashboard/homework/${a.id}`} className={s.card}>
                    <div className={s.cardMain}>
                      <h3>{a.title}</h3>
                      <div className={s.cardMeta}>
                        <span><UsersRound aria-hidden />{a.group_name}</span>
                        <span className={overdue && a.my && ["PENDING", "MISSED"].includes(a.my.status) ? s.overdue : undefined}>
                          <CalendarClock aria-hidden />{a.due_at ? `Muddat: ${fmtDateTime(a.due_at, tz)}` : "Muddatsiz"}
                        </span>
                        {a.files.length > 0 && <span><Paperclip aria-hidden />{a.files.length}</span>}
                      </div>
                    </div>
                    <div className={s.cardSide}>
                      {a.stats ? (
                        <>
                          <div className={s.progress} title="Topshirganlar">
                            <Progress value={a.stats.total ? (a.stats.submitted / a.stats.total) * 100 : 0} label="Topshirganlar" />
                            <b>{a.stats.submitted}/{a.stats.total}</b>
                          </div>
                          {a.stats.submitted > a.stats.graded ? <Badge tone="warning" dot>{a.stats.submitted - a.stats.graded} ta baholanmagan</Badge> : a.stats.graded > 0 ? <Badge tone="success">Baholangan</Badge> : null}
                        </>
                      ) : a.my ? (
                        <>
                          <Badge tone={HW_TONE[a.my.status]} dot>{labels[a.my.status]}</Badge>
                          {a.my.score != null && <span className={s.score}>{a.my.score}<small>/{a.max_score}</small></span>}
                        </>
                      ) : null}
                    </div>
                  </Link>
                </li>
              );
            })}
          </ul>
        )}
      {teacher && <AssignmentDialog open={creating} onClose={() => setCreating(false)} groups={groups} onSaved={() => void load()} />}
    </>
  );
}

function Marks({ marks, tz }: { marks: { data: MarkRow[]; average_percent: number | null } | null; tz: string }) {
  if (!marks) return <Card padded><p className="subtle" style={{ margin: 0 }}>Yuklanmoqda…</p></Card>;
  if (marks.data.length === 0) return <Card><EmptyState icon={<Star />} title="Hali baho yo'q" /></Card>;
  return (
    <div className={s.marks}>
      <div className="stats-grid" style={{ marginBottom: 0 }}>
        <StatCard label="O'rtacha natija" value={marks.average_percent != null ? `${marks.average_percent}%` : "—"} progress={marks.average_percent} icon={<Star />}
          tone={marks.average_percent == null ? "accent" : marks.average_percent >= 80 ? "success" : marks.average_percent >= 60 ? "warning" : "danger"} hint={`${marks.data.length} ta baho`} />
      </div>
      <ul className={s.cards}>
        {marks.data.map((m, i) => (
          <li key={i} className={s.card}>
            <div className={s.cardMain}>
              <h3>{m.type === "homework" ? <Link className="a" href={`/dashboard/homework/${m.assignment_id}`}>{m.title}</Link> : (m.title || "Mashg'ulot")}</h3>
              <div className={s.cardMeta}>
                <span><UsersRound aria-hidden />{m.group_name}</span>
                <span>{m.type === "homework" ? "Uyga vazifa" : "Mashg'ulotdagi baho"} · {fmtDate(m.date, tz)}</span>
              </div>
              {m.note && <p className="subtle" style={{ margin: 0 }}>{m.note}</p>}
            </div>
            <div className={s.cardSide}><span className={s.score}>{m.score}<small>/{m.max}</small></span></div>
          </li>
        ))}
      </ul>
    </div>
  );
}
