"use client";

import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import { AlertTriangle, ArrowDownRight, ArrowUpRight, Banknote, CalendarCheck, ClipboardCheck, Clock, GraduationCap, NotebookPen, Send, UserMinus, UserPlus } from "lucide-react";
import { Card, CardHeader, Dialog, EmptyState, Field, PageHeader, Select, StatCard, Table, Td, Th } from "@/components/ui";
import LiveStatus from "@/components/LiveStatus";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { MONTHS, fmtMoney } from "@/lib/format";
import { monthTitle } from "@/lib/dates";
import type { Kpi, KpiMonth, KpiStudent } from "@/lib/kpi";
import { refreshSeconds } from "@/lib/settings";
import { usePolling } from "@/lib/usePolling";
import type { Refs } from "./ReportsView";
import s from "./reports.module.css";

const monthLabel = (m: string) => `${MONTHS[+m.slice(5, 7) - 1]} ${m.slice(2, 4)}`;
const pct = (v: number | null) => (v == null ? "—" : `${v}%`);

/** Oyma-oy o'zgarish belgisi. higherIsBetter=false — kamayish yaxshi (masalan, chiqib ketish). */
function Delta({ now, prev, unit = "", higherIsBetter = true }: { now: number | null; prev: number | null; unit?: string; higherIsBetter?: boolean }) {
  if (now == null || prev == null) return null;
  const d = Math.round((now - prev) * 10) / 10;
  if (d === 0) return <span className={s.flat}>o&apos;tgan oy bilan teng</span>;
  const good = d > 0 === higherIsBetter;
  const Icon = d > 0 ? ArrowUpRight : ArrowDownRight;
  return (
    <span className={good ? s.up : s.down}>
      <Icon aria-hidden />{d > 0 ? "+" : ""}{d}{unit} <span className="sr-only">o&apos;tgan oyga nisbatan</span><span aria-hidden>o&apos;tgan oyga</span>
    </span>
  );
}

export default function KpiView({ refs, switcher }: { refs: Refs; switcher: ReactNode }) {
  const st = useSiteSettings();
  const [f, setF] = useState({ months: "6", group_id: "", course_id: "", teacher_id: "" });
  const [data, setData] = useState<Kpi | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [drill, setDrill] = useState<{ type: "new" | "left" | "active"; month: string } | null>(null);
  const qs = useMemo(() => new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString(), [f]);

  const fetcher = useCallback(async (signal: AbortSignal) => {
    const r = await call<Kpi>("GET", `reports/kpi?${qs}`, undefined, { signal });
    if (signal.aborted) return null;
    if (!r.ok) { setError(errorText(r.data)); return null; }
    setError(null);
    return r.data;
  }, [qs]);
  const live = usePolling(fetcher, setData, { seconds: Math.max(60, refreshSeconds(st) * 3), immediate: true });
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF((x) => ({ ...x, [k]: e.target.value }));
  const groups = refs.groups.filter((g) => (!f.course_id || g.course_id === +f.course_id) && (!f.teacher_id || g.teacher_id === +f.teacher_id));

  const cur = data?.series[data.series.length - 1];
  const prev = data?.series[data.series.length - 2];
  const money = data?.money;
  const mNow = money?.series[money.series.length - 1];
  const mPrev = money?.series[money.series.length - 2];

  return (
    <>
      <PageHeader title="Hisobotlar" description="Oylik ko'rsatkichlar: o'quvchilar, davomat, natijalar, o'qituvchi soatlari va to'lovlar"
        actions={<LiveStatus {...live} lastUpdated={live.lastUpdated} onRefresh={live.refresh} seconds={Math.max(60, refreshSeconds(st) * 3)} />} />
      {switcher}
      <Card className={s.filters}>
        <div className={s.grid} data-open>
          <Field label="Davr"><Select value={f.months} onChange={set("months")}>{[3, 6, 12].map((n) => <option key={n} value={n}>So&apos;nggi {n} oy</option>)}</Select></Field>
          <Field label="Kurs"><Select value={f.course_id} onChange={set("course_id")}><option value="">Barchasi</option>{refs.courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}</Select></Field>
          {refs.teachers.length > 0 && <Field label="O'qituvchi"><Select value={f.teacher_id} onChange={set("teacher_id")}><option value="">Barchasi</option>{refs.teachers.map((t) => <option key={t.id} value={t.id}>{[t.first_name, t.last_name].filter(Boolean).join(" ")}</option>)}</Select></Field>}
          <Field label="Guruh"><Select value={f.group_id} onChange={set("group_id")}><option value="">Barchasi</option>{groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}</Select></Field>
        </div>
      </Card>

      {error ? <Card><EmptyState icon={<AlertTriangle />} title="Ko'rsatkichlar olinmadi">{error}</EmptyState></Card> : !data || !cur ? (
        <div className="stats-grid">{[0, 1, 2, 3].map((i) => <StatCard key={i} label="…" value="…" />)}</div>
      ) : (
        <>
          <h2 className={s.kpiHeading}>{monthTitle(cur.month + "-01")}{cur.partial ? " — joriy oy" : ""}</h2>
          <div className="stats-grid">
            <StatCard label="Faol o'quvchilar" value={cur.active_students} icon={<GraduationCap />} tone="info" hint={<Delta now={cur.active_students} prev={prev?.active_students ?? null} />} />
            <StatCard label="Yangi / chiqqan" value={<>{cur.new_students} <span className="subtle">/</span> {cur.left_students}</>} icon={<UserPlus />}
              tone={cur.left_students > cur.new_students ? "warning" : "success"} hint={cur.churn_percent != null ? `Chiqib ketish: ${cur.churn_percent}%` : undefined} />
            <StatCard label="Davomat" value={pct(cur.attendance_rate)} progress={cur.attendance_rate} icon={<CalendarCheck />}
              tone={cur.attendance_rate == null ? "accent" : cur.attendance_rate >= data.targets.attendance ? "success" : "warning"}
              hint={<Delta now={cur.attendance_rate} prev={prev?.attendance_rate ?? null} unit="%" />} />
            <StatCard label="Testdan o'tish" value={pct(cur.quiz_pass_rate)} progress={cur.quiz_pass_rate} icon={<ClipboardCheck />}
              tone={cur.quiz_pass_rate == null ? "accent" : cur.quiz_pass_rate >= data.targets.quiz_pass ? "success" : "warning"} hint={`${cur.quiz_attempts} ta urinish`} />
            <StatCard label="Vazifa topshirish" value={pct(cur.homework_rate)} progress={cur.homework_rate} icon={<NotebookPen />}
              hint={cur.homework_expected ? `${cur.homework_submitted}/${cur.homework_expected}` : "Vazifa berilmagan"} />
            <StatCard label="O'qituvchi soatlari" value={cur.teacher_hours} icon={<Clock />} hint={<Delta now={cur.teacher_hours} prev={prev?.teacher_hours ?? null} unit=" soat" />} />
            {data.telegram_reach_percent != null && <StatCard label="Telegram qamrovi" value={pct(data.telegram_reach_percent)} progress={data.telegram_reach_percent} icon={<Send />} hint="Faol o'quvchilardan" />}
            {money && mNow && (
              <StatCard label="Tushum" value={fmtMoney(mNow.income, money.currency)} icon={<Banknote />} tone="success"
                hint={<Delta now={mNow.income} prev={mPrev?.income ?? null} />} />
            )}
            {money && <StatCard label="Qarzdorlik" value={fmtMoney(money.debt_total, money.currency)} icon={<UserMinus />} tone={money.debt_total > 0 ? "danger" : "success"} hint={`${money.debtors} ta qarzdor`} />}
          </div>

          <div className={s.kpiCharts}>
            <Card>
              <CardHeader title="Davomat, oylar bo'yicha" description={`Maqsad: ${data.targets.attendance}%`} />
              <MonthBars series={data.series} value={(m) => m.attendance_rate} max={100} unit="%" target={data.targets.attendance} label="Davomat foizi" />
            </Card>
            <Card>
              <CardHeader title="Faol o'quvchilar" description="Oy oxiridagi holat" />
              <MonthBars series={data.series} value={(m) => m.active_students} label="Faol o'quvchilar" />
            </Card>
          </div>

          <Card>
            <CardHeader title="Oylar bo'yicha jadval" description="Raqamni bosing — o'quvchilar ro'yxati" />
            <Table caption="Oylik ko'rsatkichlar">
              <thead><tr><Th>Ko&apos;rsatkich</Th>{data.series.map((m) => <Th key={m.month} numeric>{monthLabel(m.month)}{m.partial ? "*" : ""}</Th>)}</tr></thead>
              <tbody>
                <MetricRow label="Faol o'quvchilar" series={data.series} cell={(m) => <button type="button" className={s.drill} onClick={() => setDrill({ type: "active", month: m.month })}>{m.active_students}</button>} />
                <MetricRow label="Yangi o'quvchilar" series={data.series} cell={(m) => m.new_students ? <button type="button" className={s.drill} onClick={() => setDrill({ type: "new", month: m.month })}>{m.new_students}</button> : 0} />
                <MetricRow label="Chiqqan o'quvchilar" series={data.series} cell={(m) => m.left_students ? <button type="button" className={s.drill} onClick={() => setDrill({ type: "left", month: m.month })}>{m.left_students}</button> : 0} />
                <MetricRow label="Chiqib ketish" series={data.series} cell={(m) => pct(m.churn_percent)} />
                <MetricRow label="Davomat" series={data.series} cell={(m) => pct(m.attendance_rate)} />
                <MetricRow label="Testdan o'tish" series={data.series} cell={(m) => pct(m.quiz_pass_rate)} />
                <MetricRow label="Vazifa topshirish" series={data.series} cell={(m) => pct(m.homework_rate)} />
                <MetricRow label="Vazifa o'rtacha bahosi" series={data.series} cell={(m) => pct(m.homework_avg_percent)} />
                <MetricRow label="Mashg'ulotlar" series={data.series} cell={(m) => m.sessions} />
                <MetricRow label="O'qituvchi soatlari" series={data.series} cell={(m) => m.teacher_hours} />
                {money && <MetricRow label="Tushum" series={data.series} cell={(m) => fmtMoney(money.series.find((x) => x.month === m.month)?.income ?? 0)} />}
                {money && <MetricRow label="Hisoblangan" series={data.series} cell={(m) => fmtMoney(money.series.find((x) => x.month === m.month)?.charged ?? 0)} />}
              </tbody>
            </Table>
            {cur.partial && <p className="subtle" style={{ margin: 0, padding: "8px 16px 16px", fontSize: "var(--text-xs)" }}>* Joriy oy — bugungi kungacha.</p>}
          </Card>

          <Card style={{ marginTop: "var(--space-4)" }}>
            <CardHeader title="O'qituvchilar: o'tilgan soatlar" description="Bekor qilinmagan, o'tgan mashg'ulotlar (oylik maosh uchun asos)" />
            {data.teachers.length === 0 ? <EmptyState compact icon={<Clock />} title="Mashg'ulot o'tilmagan" /> : (
              <Table stack caption="O'qituvchi soatlari">
                <thead><tr><Th>O&apos;qituvchi</Th>{data.months.map((m) => <Th key={m} numeric>{monthLabel(m)}</Th>)}</tr></thead>
                <tbody>
                  {data.teachers.map((t) => (
                    <tr key={t.teacher_id}>
                      <Td><b>{t.name}</b></Td>
                      {data.months.map((m) => <Td key={m} numeric label={monthLabel(m)}>{t.hours[m] ? `${t.hours[m]} soat` : "—"}{t.sessions[m] ? <div className="subtle">{t.sessions[m]} ta</div> : null}</Td>)}
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
        </>
      )}
      <DrillDialog drill={drill} qs={qs} onClose={() => setDrill(null)} />
    </>
  );
}

function MetricRow({ label, series, cell }: { label: string; series: KpiMonth[]; cell: (m: KpiMonth) => ReactNode }) {
  return <tr><Th scope="row" className={s.rowHead}>{label}</Th>{series.map((m) => <Td key={m.month} numeric>{cell(m)}</Td>)}</tr>;
}

/** Oylik ustunli grafik (SVG). Har bir ustunning qiymati yozib qo'yilgan — rangga bog'liq emas. */
function MonthBars({ series, value, max, unit = "", target, label }: { series: KpiMonth[]; value: (m: KpiMonth) => number | null; max?: number; unit?: string; target?: number; label: string }) {
  const W = 480, H = 170, P = 24;
  const vals = series.map(value);
  const top = max ?? Math.max(1, ...vals.map((v) => v ?? 0)) * 1.15;
  const n = series.length;
  const slot = (W - P * 2) / n;
  const bw = Math.min(44, slot * 0.6);
  const y = (v: number) => H - P - ((H - P * 2) * v) / top;
  return (
    <div className={s.chart}>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label={`${label}: ${series.map((m, i) => `${monthLabel(m.month)} — ${vals[i] ?? "ma'lumot yo'q"}${vals[i] != null ? unit : ""}`).join(", ")}`}>
        <line x1={P} x2={W - P} y1={H - P} y2={H - P} className={s.gridLine} />
        {target != null && (
          <g>
            <line x1={P} x2={W - P} y1={y(target)} y2={y(target)} className={s.target} />
            <text x={W - P} y={y(target) - 4} textAnchor="end" className={s.axis}>{target}{unit}</text>
          </g>
        )}
        {series.map((m, i) => {
          const v = vals[i];
          const x = P + slot * i + (slot - bw) / 2;
          return (
            <g key={m.month}>
              {v != null && <rect x={x} y={y(v)} width={bw} height={Math.max(1, H - P - y(v))} rx={4} className={target != null ? (v >= target ? s.good : v >= target - 15 ? s.mid : s.bad) : s.neutralBar} />}
              <text x={x + bw / 2} y={v != null ? y(v) - 5 : H - P - 5} textAnchor="middle" className={s.value}>{v != null ? `${v}${unit}` : "—"}</text>
              <text x={x + bw / 2} y={H - 6} textAnchor="middle" className={s.axis}>{monthLabel(m.month)}</text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

function DrillDialog({ drill, qs, onClose }: { drill: { type: "new" | "left" | "active"; month: string } | null; qs: string; onClose: () => void }) {
  const [rows, setRows] = useState<KpiStudent[] | null>(null);
  useEffect(() => {
    if (!drill) return;
    let alive = true;
    setRows(null);
    const p = new URLSearchParams(qs);
    p.delete("months");
    call<{ data: KpiStudent[] }>("GET", `reports/kpi/students?type=${drill.type}&month=${drill.month}${p.toString() ? `&${p}` : ""}`)
      .then((r) => alive && setRows(r.ok ? r.data.data : []));
    return () => { alive = false; };
  }, [drill, qs]);
  const title = drill ? `${{ new: "Yangi o'quvchilar", left: "Chiqqan o'quvchilar", active: "Faol o'quvchilar" }[drill.type]} — ${monthLabel(drill.month)}` : "";
  return (
    <Dialog open={Boolean(drill)} onClose={onClose} title={title} width={640}>
      {!rows ? <p className="subtle">Yuklanmoqda…</p> : rows.length === 0 ? <p className="subtle">Ro&apos;yxat bo&apos;sh.</p> : (
        <Table stack caption={title}>
          <thead><tr><Th>O&apos;quvchi</Th><Th>Guruh</Th><Th>Qo&apos;shilgan</Th><Th>Chiqqan</Th></tr></thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={`${r.student_id}-${r.group_id}-${i}`}>
                <Td><b>{r.name}</b>{r.phone && <div className="subtle">{r.phone}</div>}</Td>
                <Td label="Guruh">{r.group}</Td>
                <Td label="Qo'shilgan">{r.joined_at ?? "—"}</Td>
                <Td label="Chiqqan">{r.left_at ?? "—"}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Dialog>
  );
}
