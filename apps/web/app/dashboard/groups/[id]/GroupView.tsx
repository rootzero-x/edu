"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import {
  BookOpen, CalendarClock, CalendarPlus, ClipboardCheck, DoorOpen, GraduationCap, Lock, MoreHorizontal, Pencil, PieChart,
  Search, Unlock, UserMinus, UserPlus, UserRound,
} from "lucide-react";
import {
  Avatar, Badge, Button, ButtonLink, Card, CardHeader, Dialog, Dropdown, EmptyState, Input, PageHeader, Progress, StatCard,
  Table, Tabs, Td, Th, cx, useConfirm, useToast,
} from "@/components/ui";
import LiveStatus from "@/components/LiveStatus";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDate, fmtTime, fmtWeekday, personName, scheduleText } from "@/lib/format";
import { sessionsPath } from "@/lib/groups";
import { refreshSeconds, statusLabels, timezone } from "@/lib/settings";
import type { GroupDetail, Person, ReportRow, SessionRow } from "@/lib/types";
import { usePolling } from "@/lib/usePolling";
import s from "./group.module.css";
import EditGroup from "./EditGroup";
import GroupHomework from "./GroupHomework";
import { bool } from "@/lib/settings";

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
  const [pending, setPending] = useState<Set<number>>(new Set());
  const [added, setAdded] = useState<number | null>(null);
  const [adding, setAdding] = useState(false);
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
  // Optimistik o'zgarish kutilayotganda serverdan kelgan eski holat uni bosib ketmasin.
  const live = usePolling(fetcher, (d) => { if (pending.size === 0) setData(d); }, { seconds });

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

  async function removeMember(m: Person) {
    if (!(await confirm({ title: "Guruhdan chiqarilsinmi?", description: `${personName(m)} guruh ro'yxatidan chiqariladi. Davomat tarixi saqlanib qoladi.`, confirmText: "Chiqarish", tone: "danger" }))) return;
    const prev = data;
    setPending((p) => new Set(p).add(m.id));
    setData((d) => ({ ...d, group: { ...d.group, students: d.group.students.filter((x) => x.id !== m.id) } }));
    const r = await call("DELETE", `groups/${id}/members/${m.id}`);
    setPending((p) => { const n = new Set(p); n.delete(m.id); return n; });
    if (!r.ok) { setData(prev); return toast.error("Chiqarilmadi", errorText(r.data)); }
    toast.success("Guruhdan chiqarildi", personName(m));
    void live.refresh();
  }

  async function addMember(p: Person) {
    const prev = data;
    setPending((x) => new Set(x).add(p.id));
    setData((d) => ({ ...d, group: { ...d.group, students: [...d.group.students, p].sort((a, b) => a.first_name.localeCompare(b.first_name)) } }));
    setAdded(p.id);
    const r = await call("POST", `groups/${id}/members`, { student_id: p.id });
    setPending((x) => { const n = new Set(x); n.delete(p.id); return n; });
    if (!r.ok) { setData(prev); return toast.error("Qo'shilmadi", errorText(r.data)); }
    toast.success("Guruhga qo'shildi", personName(p));
    void live.refresh();
  }

  async function generate() {
    const r = await call("POST", `groups/${id}/sessions/generate`, {});
    if (!r.ok) return toast.error("Bajarilmadi", errorText(r.data));
    toast.success(r.data.created ? `${r.data.created} ta yangi mashg'ulot qo'shildi` : "Jadval allaqachon to'liq", `${r.data.from} — ${r.data.to}`);
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
        {x.cancelled ? <Badge>Bekor qilingan</Badge>
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
                  { label: "Jadvaldan mashg'ulotlarni yangilash", icon: <CalendarPlus />, onSelect: generate },
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
          <span>Oxirgi mashg&apos;ulot ({fmtDate(past[0].starts_at, tz)} {fmtTime(past[0].starts_at, tz)}) davomati hali to&apos;liq belgilanmagan ({past[0].marked_count}/{members}).</span>
          <ButtonLink href={`/dashboard/sessions/${past[0].id}`} size="sm">Belgilash</ButtonLink>
        </div>
      )}
      <section className={s.stats} aria-label="Guruh statistikasi">
        <StatCard label="O'quvchilar" value={group.students.length} icon={<GraduationCap />} tone="info" />
        <StatCard label={seeAll ? "O'rtacha davomat" : "Davomat"} value={avg == null ? "—" : `${avg}%`} icon={<PieChart />} progress={avg} tone={avg == null ? "accent" : avg >= 85 ? "success" : avg >= 65 ? "warning" : "danger"} />
        <StatCard label="Keyingi mashg'ulot" value={next ? fmtTime(next.starts_at, tz) : "—"} hint={next ? `${fmtDate(next.starts_at, tz)} · ${fmtWeekday(next.starts_at, tz)}` : "Rejalashtirilmagan"} icon={<CalendarClock />} />
        {canMark && <StatCard label="Belgilanmagan" value={unmarked} icon={<ClipboardCheck />} tone={unmarked ? "warning" : "success"} hint="O'tgan mashg'ulotlar" />}
      </section>

      <Tabs
        label="Guruh bo'limlari"
        items={[
          {
            id: "students", label: "O'quvchilar", count: group.students.length,
            content: (
              <Card>
                <CardHeader title="O'quvchilar ro'yxati" actions={manage && <Button size="sm" icon={<UserPlus />} onClick={() => setAdding(true)}>Qo&apos;shish</Button>} />
                {group.students.length === 0 ? (
                  <EmptyState compact icon={<GraduationCap />} title="Guruhda o'quvchi yo'q" action={manage && <Button icon={<UserPlus />} onClick={() => setAdding(true)}>O&apos;quvchi qo&apos;shish</Button>} />
                ) : (
                  <ul className={s.members}>
                    {group.students.map((m) => {
                      const r = report.find((x) => x.student_id === m.id);
                      return (
                        <li key={m.id} className={cx(s.member, pending.has(m.id) && s.pending, added === m.id && s.added)}>
                          <Avatar name={personName(m)} />
                          <div className={s.memberText}><strong>{personName(m)}</strong>{m.phone && <span>{m.phone}</span>}</div>
                          {r && <div className={s.rate} title="Davomat"><Progress value={Number(r.rate)} label="Davomat" /><b>{r.rate}%</b></div>}
                          {manage && <Button size="sm" variant="dangerGhost" iconOnly aria-label={`${personName(m)} — guruhdan chiqarish`} icon={<UserMinus />} onClick={() => removeMember(m)} />}
                        </li>
                      );
                    })}
                  </ul>
                )}
              </Card>
            ),
          },
          {
            id: "attendance", label: "Davomat",
            content: (
              <Card>
                <CardHeader title="Davomat hisoboti" description="Bekor qilinmagan, o'tgan mashg'ulotlar bo'yicha" />
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
            id: "sessions", label: "Mashg'ulotlar", count: unmarked || undefined,
            content: (
              <div className={s.two}>
                <Card>
                  <CardHeader title="O'tgan mashg'ulotlar" description={`${past.length} ta`} />
                  {past.length === 0 ? <EmptyState compact icon={<CalendarClock />} title="Hali mashg'ulot bo'lmagan" /> : <ul className={s.sessions}>{past.slice(0, 30).map(sessionLink)}</ul>}
                </Card>
                <Card>
                  <CardHeader title="Keyingi mashg'ulotlar" description={`${upcoming.length} ta`} actions={manage && <Button size="sm" variant="soft" icon={<CalendarPlus />} onClick={generate}>Yangilash</Button>} />
                  {upcoming.length === 0 ? <EmptyState compact icon={<CalendarPlus />} title="Rejalashtirilmagan" /> : <ul className={s.sessions}>{upcoming.slice(0, 12).map(sessionLink)}</ul>}
                </Card>
              </div>
            ),
          },
          ...(bool(st, "homework.enabled", true) ? [{ id: "homework", label: "Uyga vazifa", content: <GroupHomework groupId={id} /> }] : []),
        ]}
      />

      {manage && (
        <AddMemberDialog open={adding} onClose={() => setAdding(false)} memberIds={new Set(group.students.map((m) => m.id))} onAdd={addMember} />
      )}
    </>
  );
}

function AddMemberDialog({ open, onClose, memberIds, onAdd }: { open: boolean; onClose: () => void; memberIds: Set<number>; onAdd: (p: Person) => void }) {
  const [q, setQ] = useState("");
  const [results, setResults] = useState<Person[] | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!open) return;
    if (timer.current) clearTimeout(timer.current);
    const ctrl = new AbortController();
    timer.current = setTimeout(async () => {
      const r = await call<{ data: Person[] }>("GET", `users?role=STUDENT&active=1${q.trim() ? `&q=${encodeURIComponent(q.trim())}` : ""}`, undefined, { signal: ctrl.signal }).catch(() => null);
      if (r?.ok) setResults(r.data.data ?? []);
    }, 250);
    return () => { ctrl.abort(); if (timer.current) clearTimeout(timer.current); };
  }, [q, open]);

  return (
    <Dialog open={open} onClose={onClose} title="O'quvchi qo'shish" description="Ism yoki telefon raqami bo'yicha qidiring" width={520}>
      <Input type="search" autoFocus placeholder="Masalan: Aziz yoki 90 123" value={q} onChange={(e) => setQ(e.target.value)} leading={<Search />} aria-label="O'quvchini qidirish" />
      {results === null ? <p className="subtle" style={{ margin: 0 }}>Yuklanmoqda…</p> : results.length === 0 ? (
        <EmptyState compact icon={<Search />} title="Topilmadi">Avval «Foydalanuvchilar» bo&apos;limida o&apos;quvchini qo&apos;shing yoki u Telegram bot orqali ro&apos;yxatdan o&apos;tsin.</EmptyState>
      ) : (
        <ul className={s.results}>
          {results.map((p) => {
            const isMember = memberIds.has(p.id);
            return (
              <li key={p.id}>
                <button type="button" disabled={isMember} onClick={() => onAdd(p)}>
                  <Avatar name={personName(p)} />
                  <span style={{ flex: 1, minWidth: 0 }}><strong>{personName(p)}</strong><br /><span className="subtle">{p.phone}</span></span>
                  {isMember ? <Badge tone="success">Guruhda</Badge> : <UserPlus size={18} aria-hidden className="subtle" />}
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </Dialog>
  );
}
