"use client";

import { useCallback, useMemo, useState } from "react";
import Link from "next/link";
import { AlertTriangle, BarChart3, SlidersHorizontal, CalendarCheck, ClipboardCheck, Download, GraduationCap, Users, UsersRound } from "lucide-react";
import { Badge, Card, CardHeader, EmptyState, Field, Input, PageHeader, Progress, Select, StatCard, Table, Tabs, Td, Th, buttonClass } from "@/components/ui";
import LiveStatus from "@/components/LiveStatus";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDate } from "@/lib/format";
import { num, refreshSeconds, statusLabels, timezone } from "@/lib/settings";
import { STATUS_TONE } from "@/lib/status";
import { usePolling } from "@/lib/usePolling";
import s from "./reports.module.css";

export type Refs = {
  groups: { id: number; name: string; course_id: number; teacher_id: number }[];
  courses: { id: number; title: string }[];
  teachers: { id: number; first_name: string; last_name: string | null }[];
};
type Counts = Record<string, number>;
type Sum = { counts: Counts; rate: number | null };
type AttendanceReport = {
  period: { from: string; to: string };
  total: Sum & { sessions: number; students: number; unmarked_sessions: number };
  by_day: (Sum & { date: string })[];
  by_group: (Sum & { group_id: number; group: string; teacher: string; course: string; sessions: number })[];
  risk: (Sum & { student_id: number; name: string; phone: string; groups: string[] })[];
  risk_threshold: number;
};
type QuizRow = { quiz_id: number; title: string; course: string; is_final: boolean; attempts: number; students: number; avg_percent: number; pass_rate: number | null };
type Overview = { groups: number; students: number; courses: number; sessions: number; new_students: number; certificates: number };
type Data = { att: AttendanceReport; quizzes: QuizRow[]; overview: Overview };

// Sana sayt vaqt zonasida (UTC emas: Toshkentda tun yarmidan 05:00 gacha "bugun" kechagi kun bo'lib qolardi).
const isoIn = (d: Date, tz: string) => new Intl.DateTimeFormat("en-CA", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit" }).format(d);
const PRESET_DAYS = [7, 30, 90];

export default function ReportsView({ refs }: { refs: Refs }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const labels = statusLabels(st);
  const iso = (d: Date) => isoIn(d, tz);
  const daysAgo = (n: number) => iso(new Date(Date.now() - n * 86400000));
  const maxDays = num(st, "reports.max_days", 366);
  const defaultDays = Math.min(num(st, "reports.default_days", 30), maxDays);
  const PRESETS = PRESET_DAYS.filter((n) => n <= maxDays).map((n): [string, number] => [`${n} kun`, n - 1]);
  const [error, setError] = useState<string | null>(null);
  const [f, setF] = useState({ from: daysAgo(defaultDays - 1), to: iso(new Date()), group_id: "", course_id: "", teacher_id: "" });
  const [showFilters, setShowFilters] = useState(false);
  const activeFilters = [f.group_id, f.course_id, f.teacher_id].filter(Boolean).length;
  const [data, setData] = useState<Data | null>(null);
  const [tab, setTab] = useState("groups");
  const qs = useMemo(() => new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString(), [f]);

  const fetcher = useCallback(async (signal: AbortSignal) => {
    const [a, q, o] = await Promise.all([
      call<AttendanceReport>("GET", `reports/attendance?${qs}`, undefined, { signal }),
      call<{ data: QuizRow[] }>("GET", `reports/quizzes?${qs}`, undefined, { signal }),
      call<Overview>("GET", `reports/overview?${qs}`, undefined, { signal }),
    ]);
    if (signal.aborted) return null;
    const bad = [a, q, o].find((x) => !x.ok);
    if (bad) {
      // Xato (masalan, juda katta davr) — eski filtr natijalari yangi filtr ostida qolib ketmasin.
      setError(errorText(bad.data));
      setData(null);
      return null;
    }
    setError(null);
    return { att: a.data, quizzes: q.data.data, overview: o.data };
  }, [qs]);
  const live = usePolling(fetcher, setData, { seconds: refreshSeconds(st), immediate: true });

  const groups = refs.groups.filter((g) => (!f.course_id || g.course_id === +f.course_id) && (!f.teacher_id || g.teacher_id === +f.teacher_id));
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((x) => ({ ...x, [k]: e.target.value, ...(k !== "group_id" ? { group_id: "" } : {}) }));
  const att = data?.att;
  const totalMarks = att ? Object.values(att.total.counts).reduce((a, b) => a + b, 0) : 0;

  return (
    <>
      <PageHeader
        title="Hisobotlar"
        description={att ? `${fmtDate(att.period.from + "T12:00:00", tz)} — ${fmtDate(att.period.to + "T12:00:00", tz)}` : "Davomat va testlar statistikasi"}
        actions={
          <>
            <LiveStatus {...live} lastUpdated={live.lastUpdated} onRefresh={live.refresh} seconds={refreshSeconds(st)} />
            <a className={buttonClass({ variant: "secondary" })} href={`/api/export/reports/attendance.csv?${qs}`} download>
              <Download aria-hidden /> CSV (Excel)
            </a>
          </>
        }
      />

      <Card className={s.filters}>
        <div className={s.presets} role="group" aria-label="Tezkor davr">
          {PRESETS.map(([label, n]) => (
            <button key={label} type="button" className={s.preset} aria-pressed={f.from === daysAgo(n) && f.to === iso(new Date())}
              onClick={() => setF((x) => ({ ...x, from: daysAgo(n), to: iso(new Date()) }))}>{label}</button>
          ))}
          <button type="button" className={`${s.preset} ${s.filterToggle}`} aria-expanded={showFilters} aria-controls="report-filters" onClick={() => setShowFilters((v) => !v)}>
            <SlidersHorizontal aria-hidden /> Filtrlar{activeFilters ? ` · ${activeFilters}` : ""}
          </button>
        </div>
        <div id="report-filters" className={s.grid} data-open={showFilters || undefined}>
          <Field label="Boshlanish"><Input type="date" value={f.from} max={f.to} onChange={set("from")} /></Field>
          <Field label="Tugash"><Input type="date" value={f.to} min={f.from} onChange={set("to")} /></Field>
          <Field label="Kurs"><Select value={f.course_id} onChange={set("course_id")}><option value="">Barchasi</option>{refs.courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}</Select></Field>
          {refs.teachers.length > 0 && (
            <Field label="O'qituvchi"><Select value={f.teacher_id} onChange={set("teacher_id")}><option value="">Barchasi</option>{refs.teachers.map((t) => <option key={t.id} value={t.id}>{[t.first_name, t.last_name].filter(Boolean).join(" ")}</option>)}</Select></Field>
          )}
          <Field label="Guruh"><Select value={f.group_id} onChange={set("group_id")}><option value="">Barchasi</option>{groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}</Select></Field>
        </div>
      </Card>

      {error ? (
        <Card><EmptyState icon={<AlertTriangle />} title="Hisobot olinmadi">{error}</EmptyState></Card>
      ) : !data ? (
        <div className="stats-grid">{[0, 1, 2, 3].map((i) => <StatCard key={i} label="…" value="…" />)}</div>
      ) : (
        <>
          <div className="stats-grid">
            <StatCard label="Davomat" value={att!.total.rate !== null ? `${att!.total.rate}%` : "—"} icon={<CalendarCheck />} progress={att!.total.rate} hint={`${totalMarks} ta belgi`} />
            <StatCard label="O'tgan darslar" value={att!.total.sessions} icon={<BarChart3 />} hint={att!.total.unmarked_sessions ? `${att!.total.unmarked_sessions} ta belgilanmagan` : "Hammasi belgilangan"} tone={att!.total.unmarked_sessions ? "warning" : "success"} />
            <StatCard label="O'quvchilar" value={data.overview.students} icon={<Users />} hint={`${data.overview.new_students} ta yangi`} />
            <StatCard label="Guruhlar" value={data.overview.groups} icon={<UsersRound />} hint={`${data.overview.certificates} ta sertifikat`} />
          </div>

          <Card>
            <CardHeader title="Kunlik davomat" description="Har bir ustun — kun bo'yicha foiz (ustiga olib boring)" />
            {att!.by_day.length === 0 ? <EmptyState compact icon={<BarChart3 />} title="Bu davrda davomat yo'q" /> : <Trend days={att!.by_day} tz={tz} />}
            <div className={s.legend}>
              {Object.entries(labels).map(([k, l]) => <Badge key={k} tone={STATUS_TONE[k] ?? "neutral"}>{l}: {att!.total.counts[k] ?? 0}</Badge>)}
            </div>
          </Card>

          <div style={{ marginTop: 16 }}>
            <Tabs
              value={tab}
              onChange={setTab}
              items={[
                { id: "groups", label: "Guruhlar", icon: <UsersRound />, count: att!.by_group.length },
                { id: "risk", label: "Xavf ostida", icon: <AlertTriangle />, count: att!.risk.length },
                { id: "quizzes", label: "Testlar", icon: <ClipboardCheck />, count: data.quizzes.length },
              ]}
            />
          </div>

          {tab === "groups" && (
            <Card>
              {att!.by_group.length === 0 ? <EmptyState compact icon={<UsersRound />} title="Ma'lumot yo'q" /> : (
                <Table stack>
                  <thead><tr><Th>Guruh</Th><Th>O&apos;qituvchi</Th><Th>Darslar</Th>{Object.keys(labels).map((k) => <Th key={k}>{labels[k]}</Th>)}<Th>Davomat</Th></tr></thead>
                  <tbody>
                    {att!.by_group.map((g) => (
                      <tr key={g.group_id}>
                        <Td><Link className="a" href={`/dashboard/groups/${g.group_id}`}>{g.group}</Link><div className="subtle">{g.course}</div></Td>
                        <Td label="O'qituvchi">{g.teacher}</Td>
                        <Td label="Darslar">{g.sessions}</Td>
                        {Object.keys(labels).map((k) => <Td key={k} label={labels[k]}>{g.counts[k] ?? 0}</Td>)}
                        <Td label="Davomat"><div className={s.rate}><Progress value={g.rate ?? 0} label="Davomat" /><b>{g.rate ?? "—"}%</b></div></Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
          )}

          {tab === "risk" && (
            <Card>
              <CardHeader title={`Davomati ${att!.risk_threshold}% dan past`} description="Eng ko'p dars qoldirganlar birinchi" />
              {att!.risk.length === 0 ? <EmptyState compact icon={<GraduationCap />} title="Xavf ostida o'quvchi yo'q" /> : (
                <Table stack>
                  <thead><tr><Th>O&apos;quvchi</Th><Th>Guruh</Th><Th>{labels.ABSENT ?? "ABSENT"}</Th><Th>Davomat</Th></tr></thead>
                  <tbody>
                    {att!.risk.map((r) => (
                      <tr key={r.student_id}>
                        <Td><b>{r.name}</b><div className="subtle">{r.phone}</div></Td>
                        <Td label="Guruh">{r.groups.join(", ")}</Td>
                        <Td label={labels.ABSENT ?? "ABSENT"}><Badge tone="danger">{r.counts.ABSENT ?? 0}</Badge></Td>
                        <Td label="Davomat"><div className={s.rate}><Progress value={r.rate ?? 0} label="Davomat" /><b>{r.rate}%</b></div></Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
          )}

          {tab === "quizzes" && (
            <Card>
              {data.quizzes.length === 0 ? <EmptyState compact icon={<ClipboardCheck />} title="Bu davrda test topshirilmagan" /> : (
                <Table stack>
                  <thead><tr><Th>Test</Th><Th>Urinishlar</Th><Th>O&apos;quvchilar</Th><Th>O&apos;rtacha</Th><Th>O&apos;tganlar</Th></tr></thead>
                  <tbody>
                    {data.quizzes.map((q) => (
                      <tr key={q.quiz_id}>
                        <Td><b>{q.title}</b> {q.is_final && <Badge tone="accent">Yakuniy</Badge>}<div className="subtle">{q.course}</div></Td>
                        <Td label="Urinishlar">{q.attempts}</Td>
                        <Td label="O'quvchilar">{q.students}</Td>
                        <Td label="O'rtacha">{q.avg_percent}%</Td>
                        <Td label="O'tganlar"><div className={s.rate}><Progress value={q.pass_rate ?? 0} label="O'tganlar" /><b>{q.pass_rate ?? "—"}%</b></div></Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
          )}
        </>
      )}
    </>
  );
}

/** Kunlik davomat foizi — kutubxonasiz SVG ustunli grafik. */
function Trend({ days, tz }: { days: (Sum & { date: string })[]; tz: string }) {
  const W = 720, H = 180, P = 28;
  const n = days.length;
  const bw = Math.max(4, Math.min(36, (W - P * 2) / n - 4));
  const x = (i: number) => P + ((W - P * 2) / n) * i + ((W - P * 2) / n - bw) / 2;
  const y = (v: number) => H - P - ((H - P * 2) * v) / 100;
  return (
    <div className={s.chart}>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Kunlik davomat foizi" preserveAspectRatio="none">
        {[0, 50, 100].map((v) => (
          <g key={v}>
            <line x1={P} x2={W - P} y1={y(v)} y2={y(v)} className={s.gridLine} />
            <text x={4} y={y(v) + 4} className={s.axis}>{v}%</text>
          </g>
        ))}
        {days.map((d, i) => {
          const v = d.rate ?? 0;
          const tone = v >= 80 ? s.good : v >= 60 ? s.mid : s.bad;
          return (
            <rect key={d.date} x={x(i)} y={y(v)} width={bw} height={Math.max(1, H - P - y(v))} rx={3} className={tone}>
              <title>{`${fmtDate(d.date + "T12:00:00", tz)}: ${d.rate ?? "—"}%`}</title>
            </rect>
          );
        })}
      </svg>
    </div>
  );
}
