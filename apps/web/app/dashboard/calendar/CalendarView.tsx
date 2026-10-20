"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { AlertTriangle, CalendarDays, ChevronLeft, ChevronRight, DoorOpen, NotebookPen, SlidersHorizontal, UserRound } from "lucide-react";
import { Badge, Button, Card, EmptyState, Field, PageHeader, Select, Tabs, cx } from "@/components/ui";
import LiveStatus from "@/components/LiveStatus";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { addDays, addMonths, dayNum, dayOf, isoWeekday, minutesOf, monthGrid, monthTitle, todayKey, weekDays, weekTitle } from "@/lib/dates";
import { WEEKDAYS, WEEKDAYS_LONG, fmtTime } from "@/lib/format";
import { num, refreshSeconds, str, timezone } from "@/lib/settings";
import { usePolling } from "@/lib/usePolling";
import RoomsDialog from "./RoomsDialog";
import s from "./calendar.module.css";

export type CalSession = {
  id: number; group_id: number; group_name: string; course_title: string | null; teacher_id: number; teacher: string | null;
  room_id: number | null; room: string | null; topic: string | null; starts_at: string; ends_at: string; cancelled: boolean;
  marked_count: number | null; students_count: number; homework_count: number; can_mark: boolean;
};
type Option = { id: number; name: string };
type CalData = { data: CalSession[]; filters: { groups: Option[]; teachers: Option[]; rooms: Option[] } };
type View = "week" | "month";

/** Guruh rangi: id dan barqaror ton (yorug' va qorong'i mavzuda ham o'qiladigan). */
const hue = (id: number) => (id * 137) % 360;

export default function CalendarView({ manage, staff }: { manage: boolean; staff: boolean }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const today = todayKey(tz);
  const [view, setView] = useState<View>(str(st, "calendar.default_view", "week") === "month" ? "month" : "week");
  const [anchor, setAnchor] = useState(today);
  const [selected, setSelected] = useState(today);
  const [f, setF] = useState({ teacher_id: "", room_id: "", group_id: "" });
  const [showFilters, setShowFilters] = useState(false);
  const [rooms, setRooms] = useState(false);
  const [data, setData] = useState<CalData | null>(null);
  const [error, setError] = useState<string | null>(null);

  const week = useMemo(() => weekDays(anchor), [anchor]);
  const grid = useMemo(() => monthGrid(anchor), [anchor]);
  const from = view === "week" ? week[0] : grid.from;
  const to = view === "week" ? week[6] : grid.to;
  const qs = useMemo(() => new URLSearchParams({ from, to, ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)) }).toString(), [from, to, f]);

  const fetcher = useCallback(async (signal: AbortSignal) => {
    const r = await call<CalData>("GET", `calendar?${qs}`, undefined, { signal });
    if (signal.aborted) return null;
    if (!r.ok) { setError(errorText(r.data)); return null; }
    setError(null);
    return r.data;
  }, [qs]);
  const live = usePolling(fetcher, setData, { seconds: refreshSeconds(st), immediate: true });

  // Hafta ko'rinishida tanlangan kun ham shu haftada bo'lsin (telefon ro'yxati uchun).
  useEffect(() => { if (view === "week" && !week.includes(selected)) setSelected(week.includes(today) ? today : week[0]); }, [view, week, selected, today]);

  const byDay = useMemo(() => {
    const m = new Map<string, CalSession[]>();
    (data?.data ?? []).forEach((x) => { const k = dayOf(x.starts_at, tz); m.set(k, [...(m.get(k) ?? []), x]); });
    return m;
  }, [data, tz]);

  const move = (dir: -1 | 1) => {
    const next = view === "week" ? addDays(anchor, dir * 7) : addMonths(anchor, dir);
    setAnchor(next);
    setSelected(view === "week" ? addDays(selected, dir * 7) : next.slice(0, 8) + "01");
  };
  const goToday = () => { setAnchor(today); setSelected(today); };
  const activeFilters = Object.values(f).filter(Boolean).length;
  const opts = data?.filters ?? { groups: [], teachers: [], rooms: [] };
  const title = view === "week" ? weekTitle(week[0], week[6]) : monthTitle(anchor);

  return (
    <>
      <PageHeader
        title="Taqvim"
        description={staff ? "Mashg'ulotlar, xonalar va o'qituvchilar bandligi" : "Mashg'ulotlaringiz jadvali"}
        actions={
          <>
            <LiveStatus {...live} lastUpdated={live.lastUpdated} onRefresh={live.refresh} seconds={refreshSeconds(st)} />
            {manage && <Button variant="secondary" icon={<DoorOpen />} onClick={() => setRooms(true)}>Xonalar</Button>}
          </>
        }
      />

      <Card className={s.toolbar}>
        <div className={s.nav}>
          <Button variant="secondary" iconOnly size="sm" aria-label={view === "week" ? "Oldingi hafta" : "Oldingi oy"} icon={<ChevronLeft />} onClick={() => move(-1)} />
          <Button variant="secondary" size="sm" onClick={goToday}>Bugun</Button>
          <Button variant="secondary" iconOnly size="sm" aria-label={view === "week" ? "Keyingi hafta" : "Keyingi oy"} icon={<ChevronRight />} onClick={() => move(1)} />
          <h2 className={s.title} aria-live="polite">{title}</h2>
        </div>
        <div className={s.right}>
          <Tabs variant="pills" label="Ko'rinish" value={view} onChange={(v) => setView(v as View)}
            items={[{ id: "week", label: "Hafta" }, { id: "month", label: "Oy" }]} />
          <Button variant="ghost" size="sm" className={s.filterToggle} icon={<SlidersHorizontal />} aria-expanded={showFilters} aria-controls="cal-filters" onClick={() => setShowFilters((v) => !v)}>
            Filtrlar{activeFilters ? ` · ${activeFilters}` : ""}
          </Button>
        </div>
        <div id="cal-filters" className={s.filters} data-open={showFilters || undefined}>
          {opts.teachers.length > 1 || f.teacher_id ? (
            <Field label="O'qituvchi"><Select size="sm" value={f.teacher_id} onChange={(e) => setF({ ...f, teacher_id: e.target.value })}><option value="">Barchasi</option>{opts.teachers.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</Select></Field>
          ) : null}
          {opts.rooms.length > 0 && (
            <Field label="Xona"><Select size="sm" value={f.room_id} onChange={(e) => setF({ ...f, room_id: e.target.value })}><option value="">Barchasi</option>{opts.rooms.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</Select></Field>
          )}
          {opts.groups.length > 1 || f.group_id ? (
            <Field label="Guruh"><Select size="sm" value={f.group_id} onChange={(e) => setF({ ...f, group_id: e.target.value })}><option value="">Barchasi</option>{opts.groups.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</Select></Field>
          ) : null}
        </div>
      </Card>

      {error ? (
        <Card><EmptyState icon={<AlertTriangle />} title="Taqvim yuklanmadi">{error}</EmptyState></Card>
      ) : view === "week" ? (
        <>
          <WeekGrid days={week} byDay={byDay} today={today} tz={tz} loading={!data}
            startHour={num(st, "calendar.day_start_hour", 8)} endHour={num(st, "calendar.day_end_hour", 21)} />
          <DayStrip days={week} byDay={byDay} today={today} selected={selected} onSelect={setSelected} />
          <Agenda day={selected} items={byDay.get(selected) ?? []} tz={tz} className={s.mobileOnly} loading={!data} />
        </>
      ) : (
        <>
          <MonthGrid grid={grid} byDay={byDay} today={today} selected={selected} onSelect={setSelected} tz={tz} />
          <Agenda day={selected} items={byDay.get(selected) ?? []} tz={tz} loading={!data} />
        </>
      )}

      <div className={s.legend} aria-hidden>
        <span><i className={s.legendCancelled} />Bekor qilingan</span>
        <span><NotebookPen />Uyga vazifa berilgan</span>
      </div>
      {manage && <RoomsDialog open={rooms} onClose={() => setRooms(false)} onChanged={() => void live.refresh()} />}
    </>
  );
}

function EventCard({ x, tz, compact }: { x: CalSession; tz: string; compact?: boolean }) {
  const label = `${fmtTime(x.starts_at, tz)}–${fmtTime(x.ends_at, tz)}, ${x.group_name}${x.room ? `, xona ${x.room}` : ""}${x.cancelled ? ", bekor qilingan" : ""}`;
  return (
    <Link href={`/dashboard/sessions/${x.id}`} className={cx(s.event, x.cancelled && s.cancelled, compact && s.compact)}
      style={{ "--h": hue(x.group_id) } as React.CSSProperties} aria-label={label} title={label}>
      <span className={s.evTime}>{fmtTime(x.starts_at, tz)}{!compact && `–${fmtTime(x.ends_at, tz)}`}</span>
      <span className={s.evName}>{x.group_name}{x.homework_count > 0 && <NotebookPen aria-hidden className={s.evIcon} />}</span>
      {!compact && (x.room || x.teacher) && <span className={s.evMeta}>{[x.room, x.teacher].filter(Boolean).join(" · ")}</span>}
    </Link>
  );
}

/** Haftalik soat jadvali (planshet/kompyuter). Ustma-ust mashg'ulotlar yonma-yon ustunlarga bo'linadi. */
function WeekGrid({ days, byDay, today, tz, startHour, endHour, loading }: {
  days: string[]; byDay: Map<string, CalSession[]>; today: string; tz: string; startHour: number; endHour: number; loading: boolean;
}) {
  const lo = Math.min(startHour, endHour - 1) * 60;
  const hi = Math.max(endHour, startHour + 1) * 60;
  const hours = Array.from({ length: (hi - lo) / 60 }, (_, i) => lo / 60 + i);
  const pct = (m: number) => ((Math.max(lo, Math.min(hi, m)) - lo) / (hi - lo)) * 100;
  return (
    <Card className={cx(s.week, s.desktopOnly)} aria-busy={loading || undefined}>
      <div className={s.weekHead}>
        <span />
        {days.map((d) => (
          <div key={d} className={cx(s.dayHead, d === today && s.today)}>
            <span>{WEEKDAYS[isoWeekday(d)]}</span><b>{dayNum(d)}</b>
          </div>
        ))}
      </div>
      <div className={s.weekBody} style={{ "--rows": hours.length } as React.CSSProperties}>
        <div className={s.hours} aria-hidden>{hours.map((h) => <span key={h}>{String(h).padStart(2, "0")}:00</span>)}</div>
        {days.map((d) => {
          const items = [...(byDay.get(d) ?? [])].sort((a, b) => a.starts_at.localeCompare(b.starts_at));
          // Oddiy ustunlarga joylash: har bir mashg'ulot birinchi bo'sh "ustun"ga.
          const lanes: number[] = [];
          const placed = items.map((x) => {
            const a = minutesOf(x.starts_at, tz);
            const b = Math.max(a + 15, minutesOf(x.ends_at, tz) || 24 * 60);
            let lane = lanes.findIndex((end) => end <= a);
            if (lane < 0) { lane = lanes.length; lanes.push(b); } else lanes[lane] = b;
            return { x, a, b, lane };
          });
          const width = 100 / Math.max(1, lanes.length);
          return (
            <div key={d} className={cx(s.dayCol, d === today && s.todayCol)} role="list" aria-label={`${WEEKDAYS_LONG[isoWeekday(d)]}, ${dayNum(d)}`}>
              {placed.map(({ x, a, b, lane }) => (
                <div key={x.id} role="listitem" className={s.slot} style={{ top: `${pct(a)}%`, height: `${Math.max(3, pct(b) - pct(a))}%`, left: `${lane * width}%`, width: `${width}%` }}>
                  <EventCard x={x} tz={tz} compact={b - a < 50} />
                </div>
              ))}
            </div>
          );
        })}
      </div>
    </Card>
  );
}

/** Telefon: hafta kunlari tasmasi (har kunda mashg'ulotlar soni). */
function DayStrip({ days, byDay, today, selected, onSelect }: { days: string[]; byDay: Map<string, CalSession[]>; today: string; selected: string; onSelect: (d: string) => void }) {
  return (
    <div className={cx(s.strip, s.mobileOnly)} role="group" aria-label="Hafta kunlari">
      {days.map((d) => {
        const n = (byDay.get(d) ?? []).filter((x) => !x.cancelled).length;
        return (
          <button key={d} type="button" className={cx(s.stripDay, d === today && s.today)} aria-pressed={d === selected} onClick={() => onSelect(d)}
            aria-label={`${WEEKDAYS_LONG[isoWeekday(d)]}, ${dayNum(d)}: ${n} ta mashg'ulot`}>
            <span>{WEEKDAYS[isoWeekday(d)]}</span><b>{dayNum(d)}</b><i aria-hidden>{n > 0 ? "•".repeat(Math.min(3, n)) : ""}</i>
          </button>
        );
      })}
    </div>
  );
}

function MonthGrid({ grid, byDay, today, selected, onSelect, tz }: {
  grid: ReturnType<typeof monthGrid>; byDay: Map<string, CalSession[]>; today: string; selected: string; onSelect: (d: string) => void; tz: string;
}) {
  return (
    <Card className={s.month}>
      <div className={s.monthHead} aria-hidden>{WEEKDAYS.slice(1).map((w) => <span key={w}>{w}</span>)}</div>
      <div className={s.monthBody} role="grid" aria-label={monthTitle(grid.month + "-01")}>
        {Array.from({ length: grid.days.length / 7 }, (_, r) => (
          <div key={r} role="row" className={s.monthRow}>
            {grid.days.slice(r * 7, r * 7 + 7).map((d) => {
              const items = byDay.get(d) ?? [];
              const active = items.filter((x) => !x.cancelled).length;
              return (
                <div key={d} role="gridcell" aria-selected={d === selected} className={cx(s.cell, !d.startsWith(grid.month) && s.outside, d === today && s.today, d === selected && s.selected)}>
                  <button type="button" className={s.cellBtn} onClick={() => onSelect(d)} aria-label={`${dayNum(d)}-kun: ${active} ta mashg'ulot`}>
                    <b>{dayNum(d)}</b>
                    {active > 0 && <span className={s.cellCount}>{active}</span>}
                  </button>
                  <div className={s.cellEvents}>
                    {items.slice(0, 3).map((x) => <EventCard key={x.id} x={x} tz={tz} compact />)}
                    {items.length > 3 && <button type="button" className={s.more} onClick={() => onSelect(d)}>+{items.length - 3}</button>}
                  </div>
                </div>
              );
            })}
          </div>
        ))}
      </div>
    </Card>
  );
}

function Agenda({ day, items, tz, className, loading }: { day: string; items: CalSession[]; tz: string; className?: string; loading: boolean }) {
  return (
    <Card className={cx(s.agenda, className)}>
      <h3 className={s.agendaTitle}>{WEEKDAYS_LONG[isoWeekday(day)]}, {dayNum(day)}.{day.slice(5, 7)}</h3>
      {loading ? <p className="subtle">Yuklanmoqda…</p> : items.length === 0 ? (
        <EmptyState compact icon={<CalendarDays />} title="Mashg'ulot yo'q" />
      ) : (
        <ul className={s.agendaList}>
          {items.map((x) => (
            <li key={x.id}>
              <Link href={`/dashboard/sessions/${x.id}`} className={cx(s.agendaItem, x.cancelled && s.cancelled)} style={{ "--h": hue(x.group_id) } as React.CSSProperties}>
                <span className={s.agendaTime}>{fmtTime(x.starts_at, tz)}<small>{fmtTime(x.ends_at, tz)}</small></span>
                <span className={s.agendaMain}>
                  <b>{x.group_name}</b>
                  <span className="subtle">{[x.course_title, x.topic].filter(Boolean).join(" · ")}</span>
                  <span className={s.agendaMeta}>
                    {x.room && <span><DoorOpen aria-hidden />{x.room}</span>}
                    {x.teacher && <span><UserRound aria-hidden />{x.teacher}</span>}
                    {x.homework_count > 0 && <span><NotebookPen aria-hidden />Vazifa</span>}
                  </span>
                </span>
                {x.cancelled ? <Badge>Bekor qilingan</Badge>
                  : x.marked_count != null && new Date(x.starts_at).getTime() <= Date.now()
                    ? <Badge tone={x.marked_count >= x.students_count && x.students_count > 0 ? "success" : "warning"} dot>{x.marked_count}/{x.students_count}</Badge>
                    : null}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
