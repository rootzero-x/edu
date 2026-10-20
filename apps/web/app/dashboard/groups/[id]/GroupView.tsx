"use client";

import Link from "next/link";
import { useCallback, useState } from "react";
import { useRouter } from "next/navigation";
import {
  BookOpen, CalendarClock, CalendarPlus, ClipboardCheck, DoorOpen, GraduationCap, Lock, MoreHorizontal, Pencil, PieChart,
  Unlock, UserRound,
} from "lucide-react";
import {
  Badge, Button, ButtonLink, Card, CardHeader, Dropdown, EmptyState, PageHeader, Progress, StatCard,
  Table, Tabs, Td, Th, useConfirm, useToast,
} from "@/components/ui";
import LiveStatus from "@/components/LiveStatus";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDate, fmtTime, fmtWeekday, personName, scheduleText } from "@/lib/format";
import { sessionsPath } from "@/lib/groups";
import { refreshSeconds, statusLabels, timezone } from "@/lib/settings";
import type { GroupDetail, ReportRow, SessionRow } from "@/lib/types";
import { usePolling } from "@/lib/usePolling";
import s from "./group.module.css";
import EditGroup from "./EditGroup";
import Members from "./Members";

type Data = { group: GroupDetail; sessions: SessionRow[]; report: ReportRow[] };

export default function GroupView({ initial, manage, canMark, seeAll }: { initial: Data; manage: boolean; canMark: boolean; seeAll: boolean }) {
  const router = useRouter();
  const toast = useToast();
  const confirm = useConfirm();
  const st = useSiteSettings();
  const [editing, setEditing] = useState(false);
  const tz = timezone(st);
  const labels = statusLabels(st);
  const seconds = refreshSeconds(st);
  const [data, setData] = useState<Data>(initial);
  const [loadedAt] = useState(() => Date.now());
  const id = initial.group.id;

  const fetcher = useCallback(async (signal: AbortSignal): Promise<Data | null> => {
    const [g, ss, r] = await Promise.all([
      call<{ group: GroupDetail }>("GET", `groups/${id}`, undefined, { signal }),
      call<{ data: SessionRow[] }>("GET", sessionsPath(id), undefined, { signal }),
      call<{ data: ReportRow[] }>("GET", `groups/${id}/attendance`, undefined, { signal }),
    ]);
    if (!g.ok || !ss.ok || !r.ok) return null;
    return { group: g.data.group, sessions: ss.data.data ?? [], report: r.data.data ?? [] };
  }, [id]);
  const live = usePolling(fetcher, setData, { seconds });

  const { group, sessions, report } = data;
  const now = Date.now();
  const past = sessions.filter((x) => new Date(x.starts_at).getTime() <= now).reverse();
  const upcoming = sessions.filter((x) => new Date(x.starts_at).getTime() > now);
  const avg = report.length ? Math.round((report.reduce((a, r) => a + Number(r.rate), 0) / report.length) * 10) / 10 : null;
  // /v1/attendance/unmarked qoidasi: belgilanganlar hozirgi a'zolardan kam bo'lsa — belgilanmagan (qisman ham).
  const members = group.students.length;
  const isUnmarked = (x: SessionRow) => members > 0 && x.marked_count < members;
  const unmarked = past.filter((x) => !x.cancelled && isUnmarked(x)).length;
  const next = upcoming.find((x) => !x.cancelled);

  async function generate() {
    const r = await call("POST", `groups/${id}/sessions/generate`, {});
    if (!r.ok) return toast.error("Bajarilmadi", errorText(r.data));
    toast.success(r.data.created ? `${r.data.created} ta yangi dars qo'shildi` : "Jadval allaqachon to'liq", `${r.data.from} — ${r.data.to}`);
    void live.refresh();
  }

  async function graduateAll() {
    if (!(await confirm({ title: "Hamma o'quvchi bitirgan deb belgilansinmi?", description: `${group.students.length} ta o'quvchi «Bitirgan» sifatida guruhdan chiqadi. Sertifikatlarni oldinroq bering.`, confirmText: "Belgilash" }))) return;
    const r = await call("POST", `groups/${id}/graduate`, {});
    if (!r.ok) return toast.error("Bajarilmadi", errorText(r.data));
    toast.success(`${r.data.graduated} ta o'quvchi bitirdi`);
    void live.refresh();
  }

  async function toggleActive() {
    if (group.active && !(await confirm({ title: "Guruh yopilsinmi?", description: "Yopilgan guruh faol ro'yxatdan chiqadi, ma'lumotlar saqlanadi.", confirmText: "Yopish", tone: "danger" }))) return;
    const prev = data;
    setData((d) => ({ ...d, group: { ...d.group, active: !d.group.active } }));
    const r = await call("PATCH", `groups/${id}`, { active: !group.active });
    if (!r.ok) { setData(prev); return toast.error("Saqlanmadi", errorText(r.data)); }
    toast.success(group.active ? "Guruh yopildi" : "Guruh qayta ochildi");
    router.refresh();
  }

  const sessionLink = (x: SessionRow) => {
    return (
      <li key={x.id} className={s.sessionItem}>
        <div className={s.when}>
          <Link href={`/dashboard/sessions/${x.id}`} className={x.cancelled ? "subtle" : undefined}>{fmtDate(x.starts_at, tz)} · {fmtWeekday(x.starts_at, tz)}</Link>
          <small>{fmtTime(x.starts_at, tz)}–{fmtTime(x.ends_at, tz)}{x.topic ? ` · ${x.topic}` : ""}</small>
        </div>
        {x.cancelled ? <Badge>Bekor</Badge>
          : new Date(x.starts_at).getTime() > now ? <Badge tone="info">Rejada</Badge>
          : !isUnmarked(x) ? <Badge tone="success" dot>Belgilangan</Badge>
          : x.marked_count > 0 ? <Badge tone="warning" dot>Qisman · {x.marked_count}/{members}</Badge>
          : <Badge tone="warning" dot>Belgilanmagan</Badge>}
      </li>
    );
  };

  const statuses = Object.keys(labels);

  return (
    <>
      <PageHeader
        back={{ href: "/dashboard/groups", label: "Guruhlar" }}
        title={group.name}
        badge={!group.active && <Badge>Yopiq</Badge>}
        actions={
          <>
            <LiveStatus {...live} lastUpdated={live.lastUpdated ?? loadedAt} onRefresh={live.refresh} seconds={seconds} />
            {manage && (
              <Dropdown
                trigger={(p) => <Button variant="secondary" iconOnly aria-label="Guruh amallari" icon={<MoreHorizontal />} {...p} />}
                items={[
                  { label: "Tahrirlash", icon: <Pencil />, onSelect: () => setEditing(true) },
                  { label: "Jadvaldan darslarni yangilash", icon: <CalendarPlus />, onSelect: generate },
                  ...(group.students.length ? [{ label: "Hammasi kursni bitirdi", icon: <GraduationCap />, onSelect: graduateAll }] : []),
                  { type: "separator" },
                  group.active
                    ? { label: "Guruhni yopish", icon: <Lock />, danger: true, onSelect: toggleActive }
                    : { label: "Guruhni qayta ochish", icon: <Unlock />, onSelect: toggleActive },
                ]}
              />
            )}
          </>
        }
      />
      {manage && <EditGroup open={editing} onClose={() => setEditing(false)} group={group} onSaved={() => { void live.refresh(); router.refresh(); }} />}
      <div className={s.meta}>
        {group.course && <span><BookOpen aria-hidden />{group.course.title}</span>}
        {group.teacher && <span><UserRound aria-hidden />{personName(group.teacher)}</span>}
        {group.room && <span><DoorOpen aria-hidden />{group.room}</span>}
        {group.schedules.length > 0 && <span><CalendarClock aria-hidden />{scheduleText(group.schedules)}</span>}
      </div>

      {canMark && past[0] && !past[0].cancelled && isUnmarked(past[0]) && (
        <div className={s.alert} role="status">
          <ClipboardCheck aria-hidden />
          <span>Oxirgi dars ({fmtDate(past[0].starts_at, tz)} {fmtTime(past[0].starts_at, tz)}) davomati hali to'liq belgilanmagan ({past[0].marked_count}/{members}).</span>
          <ButtonLink href={`/dashboard/sessions/${past[0].id}`} size="sm">Belgilash</ButtonLink>
        </div>
      )}
      <section className={s.stats} aria-label="Guruh statistikasi">
        <StatCard label="O'quvchilar" value={group.students.length} icon={<GraduationCap />} tone="info" />
        <StatCard label={seeAll ? "O'rtacha davomat" : "Davomat"} value={avg == null ? "—" : `${avg}%`} icon={<PieChart />} progress={avg} tone={avg == null ? "accent" : avg >= 85 ? "success" : avg >= 65 ? "warning" : "danger"} />
        <StatCard label="Keyingi dars" value={next ? fmtTime(next.starts_at, tz) : "—"} hint={next ? `${fmtDate(next.starts_at, tz)} · ${fmtWeekday(next.starts_at, tz)}` : "Rejalashtirilmagan"} icon={<CalendarClock />} />
        {canMark && <StatCard label="Belgilanmagan" value={unmarked} icon={<ClipboardCheck />} tone={unmarked ? "warning" : "success"} hint="O'tgan darslar" />}
      </section>

      <Tabs
        label="Guruh bo'limlari"
        items={[
          {
            id: "students", label: "O'quvchilar", count: group.students.length,
            content: <Members groupId={id} students={group.students} report={report} manage={manage} staff={canMark || manage} onChanged={() => void live.refresh()} />,
          },
          {
            id: "attendance", label: "Davomat",
            content: (
              <Card>
                <CardHeader title="Davomat hisoboti" description="Bekor qilinmagan, o'tgan darslar bo'yicha" />
                {report.length === 0 ? <EmptyState compact icon={<PieChart />} title="Hali ma'lumot yo'q" /> : (
                  <Table stack caption="Davomat hisoboti">
                    <thead><tr><Th>O&apos;quvchi</Th>{statuses.map((k) => <Th key={k} numeric>{labels[k]}</Th>)}<Th>Foiz</Th></tr></thead>
                    <tbody>
                      {report.map((r) => (
                        <tr key={r.student_id}>
                          <Td>{r.name}</Td>
                          {statuses.map((k) => <Td key={k} numeric label={labels[k]}>{r.counts[k] ?? 0}</Td>)}
                          <Td label="Foiz"><div className={s.rate}><Progress value={Number(r.rate)} label="Davomat" /><b>{r.rate}%</b></div></Td>
                        </tr>
                      ))}
                    </tbody>
                  </Table>
                )}
              </Card>
            ),
          },
          {
            id: "sessions", label: "Darslar", count: unmarked || undefined,
            content: (
              <div className={s.two}>
                <Card>
                  <CardHeader title="O'tgan darslar" description={`${past.length} ta`} />
                  {past.length === 0 ? <EmptyState compact icon={<CalendarClock />} title="Hali dars bo'lmagan" /> : <ul className={s.sessions}>{past.slice(0, 30).map(sessionLink)}</ul>}
                </Card>
                <Card>
                  <CardHeader title="Keyingi darslar" description={`${upcoming.length} ta`} actions={manage && <Button size="sm" variant="soft" icon={<CalendarPlus />} onClick={generate}>Yangilash</Button>} />
                  {upcoming.length === 0 ? <EmptyState compact icon={<CalendarPlus />} title="Rejalashtirilmagan" /> : <ul className={s.sessions}>{upcoming.slice(0, 12).map(sessionLink)}</ul>}
                </Card>
              </div>
            ),
          },
        ]}
      />

    </>
  );
}
