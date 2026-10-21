"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ArrowRightLeft, CalendarCheck, FileSpreadsheet, GraduationCap, History, MoreHorizontal, PauseCircle, PlayCircle, Search,
  UserMinus, UserPlus, UserRound,
} from "lucide-react";
import {
  Avatar, Badge, Button, ButtonLink, Card, CardHeader, Checkbox, Dialog, Dropdown, EmptyState, Field, Input, Progress, cx,
  useConfirm, usePrompt, useToast,
} from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import {
  ENROLLMENT_TONE, enrollmentLabels, fmtDay, freezeReasons, leaveReasons, reasonRequired, todayIso,
  type FormerMember, type MemberInfo, type TimelineRow,
} from "@/lib/enrollment";
import { personName } from "@/lib/format";
import { timezone } from "@/lib/settings";
import { billingLabels } from "@/lib/billing";
import type { GroupRow, Person, ReportRow } from "@/lib/types";
import s from "./group.module.css";

/**
 * Guruh a'zolari: holat (o'qimoqda / muzlatilgan), amallar (muzlatish, ko'chirish, chiqarish, bitirish),
 * chiqib ketganlar ro'yxati va bir nechta o'quvchini birdan qo'shish.
 */
export default function Members({ groupId, students, report, manage, staff, onChanged }: {
  groupId: number; students: Person[]; report: ReportRow[]; manage: boolean; staff: boolean; onChanged: () => void;
}) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const labels = enrollmentLabels(st);
  const toast = useToast();
  const prompt = usePrompt();
  const confirm = useConfirm();
  const [info, setInfo] = useState<{ current: MemberInfo[]; former: FormerMember[] } | null>(null);
  const [adding, setAdding] = useState(false);
  const [history, setHistory] = useState<{ person: Person; rows: TimelineRow[] | null } | null>(null);
  const [showFormer, setShowFormer] = useState(false);

  const load = useCallback(async () => {
    if (!staff) return;
    const r = await call<{ current: MemberInfo[]; former: FormerMember[] }>("GET", `groups/${groupId}/members`);
    if (r.ok) setInfo(r.data);
  }, [groupId, staff]);
  // Guruh ma'lumoti (polling) yangilanganda holatlar ham yangilanadi.
  const key = students.map((x) => x.id).join(",");
  useEffect(() => { void load(); }, [load, key]);

  const byId = new Map((info?.current ?? []).map((m) => [m.id, m]));
  const done = (title: string, desc?: string) => { toast.success(title, desc); void load(); onChanged(); };
  const reasonOptions = (list: string[]) => [{ value: "", label: reasonRequired(st) ? "— tanlang —" : "— ko'rsatilmagan —" }, ...list.map((r) => ({ value: r, label: r }))];

  const freeze = (m: Person) => prompt({
    title: "Muzlatish",
    description: `${personName(m)} — muzlatilgan kunlar uchun to'lov hisoblanmaydi.`,
    fields: [
      { name: "from", label: "Qaysi kundan", type: "date", required: true, defaultValue: todayIso(tz) },
      { name: "to", label: "Qaysi kungacha (shu kun ham)", type: "date", required: true, defaultValue: todayIso(tz, 13) },
      { name: "reason", label: "Sabab", type: "select", required: reasonRequired(st), options: reasonOptions(freezeReasons(st)) },
      { name: "note", label: "Izoh", type: "textarea" },
    ],
    submitText: "Muzlatish",
    onSubmit: async (v) => {
      const r = await call("POST", `groups/${groupId}/members/${m.id}/freeze`, { ...v, reason: v.reason || null, note: v.note || null });
      if (!r.ok) return errorText(r.data);
      done("Muzlatildi", `${fmtDay(v.from)} — ${fmtDay(v.to)}${r.data.refunded ? ` · ${billingLabels(st).money(r.data.refunded)} qaytarildi` : ""}`);
    },
  });

  async function unfreeze(m: MemberInfo) {
    const planned = m.status !== "FROZEN";
    if (!(await confirm({
      title: planned ? "Rejalashtirilgan muzlatish bekor qilinsinmi?" : "Muzlatish tugatilsinmi?",
      description: planned ? `${fmtDay(m.frozen_from)} — ${fmtDay(m.frozen_to)}` : `${personName(m)} bugundan yana o'qiydi va to'lov hisoblanadi.`,
      confirmText: planned ? "Bekor qilish" : "Tugatish",
    }))) return;
    const r = await call("POST", `groups/${groupId}/members/${m.id}/unfreeze`, {});
    if (!r.ok) return toast.error("Bajarilmadi", errorText(r.data));
    done(planned ? "Muzlatish bekor qilindi" : "Muzlatish tugatildi", personName(m));
  }

  async function transfer(m: Person) {
    const g = await call<{ data: GroupRow[] }>("GET", "groups");
    const options = (g.data.data ?? []).filter((x) => x.active && x.id !== groupId).map((x) => ({ value: String(x.id), label: `${x.name}${x.course ? ` · ${x.course.title}` : ""}` }));
    if (!options.length) return toast.info("Boshqa faol guruh yo'q");
    await prompt({
      title: "Boshqa guruhga o'tkazish",
      description: `${personName(m)}: bu guruhdagi o'qish tanlangan kundan oldingi kun bilan yopiladi, tarix saqlanadi.`,
      fields: [
        { name: "to_group_id", label: "Yangi guruh", type: "select", required: true, options: [{ value: "", label: "— tanlang —" }, ...options] },
        { name: "date", label: "Yangi guruhda qaysi kundan", type: "date", required: true, defaultValue: todayIso(tz) },
        { name: "reason", label: "Sabab", type: "select", options: [{ value: "", label: "— ko'rsatilmagan —" }, ...leaveReasons(st).map((r) => ({ value: r, label: r }))] },
        { name: "note", label: "Izoh", type: "textarea" },
      ],
      submitText: "O'tkazish",
      onSubmit: async (v) => {
        const r = await call("POST", `groups/${groupId}/members/${m.id}/transfer`, { ...v, to_group_id: Number(v.to_group_id), reason: v.reason || null, note: v.note || null });
        if (!r.ok) return errorText(r.data);
        done("Boshqa guruhga o'tkazildi", options.find((o) => o.value === v.to_group_id)?.label);
      },
    });
  }

  const leave = (m: Person) => prompt({
    title: "Guruhdan chiqarish",
    description: `${personName(m)} ro'yxatdan chiqadi. Davomat va to'lov tarixi saqlanadi, sabab hisobotlarda ko'rinadi.`,
    fields: [
      { name: "reason", label: "Sabab", type: "select", required: reasonRequired(st), options: reasonOptions(leaveReasons(st)) },
      { name: "date", label: "Oxirgi o'qigan kuni", type: "date", required: true, defaultValue: todayIso(tz) },
      { name: "note", label: "Izoh", type: "textarea" },
    ],
    submitText: "Chiqarish",
    onSubmit: async (v) => {
      const r = await call("POST", `groups/${groupId}/members/${m.id}/leave`, { ...v, reason: v.reason || null, note: v.note || null });
      if (!r.ok) return errorText(r.data);
      done("Guruhdan chiqarildi", personName(m));
    },
  });

  const graduate = (m: Person) => prompt({
    title: "Bitirdi deb belgilash",
    description: `${personName(m)} kursni tamomladi — guruh ro'yxatidan «Bitirgan» sifatida chiqadi.`,
    fields: [{ name: "date", label: "Bitirgan sana", type: "date", required: true, defaultValue: todayIso(tz) }],
    submitText: "Belgilash",
    onSubmit: async (v) => {
      const r = await call("POST", `groups/${groupId}/members/${m.id}/graduate`, v);
      if (!r.ok) return errorText(r.data);
      done("Bitirgan deb belgilandi", personName(m));
    },
  });

  async function openHistory(p: Person) {
    setHistory({ person: p, rows: null });
    const r = await call<{ data: TimelineRow[] }>("GET", `groups/${groupId}/members/${p.id}/history`);
    setHistory({ person: p, rows: r.ok ? r.data.data ?? [] : [] });
  }

  const statusBadge = (m?: MemberInfo) => {
    if (!m) return null;
    if (m.status === "FROZEN") return <Badge tone="info" icon={<PauseCircle size={14} aria-hidden />}>{labels.FROZEN} · {fmtDay(m.until)} gacha</Badge>;
    if (m.frozen_from) return <Badge tone="neutral" title={m.reason ?? undefined}>Muzlatish: {fmtDay(m.frozen_from)} — {fmtDay(m.frozen_to)}</Badge>;
    return null;
  };

  const former = info?.former ?? [];

  return (
    <>
      <Card>
        <CardHeader
          title="O'quvchilar ro'yxati"
          actions={manage && (
            <>
              <ButtonLink href={`/dashboard/users?import=1&group=${groupId}`} size="sm" variant="ghost" icon={<FileSpreadsheet />}>Import</ButtonLink>
              <Button size="sm" icon={<UserPlus />} onClick={() => setAdding(true)}>Qo&apos;shish</Button>
            </>
          )}
        />
        {students.length === 0 ? (
          <EmptyState compact icon={<GraduationCap />} title="Guruhda o'quvchi yo'q" action={manage && <Button icon={<UserPlus />} onClick={() => setAdding(true)}>O&apos;quvchi qo&apos;shish</Button>} />
        ) : (
          <ul className={s.members}>
            {students.map((p) => {
              const m = byId.get(p.id);
              const r = report.find((x) => x.student_id === p.id);
              const frozenOrPlanned = m && (m.status === "FROZEN" || m.frozen_from);
              return (
                <li key={p.id} className={cx(s.member, m?.status === "FROZEN" && s.frozen)}>
                  <Avatar name={personName(p)} />
                  <div className={s.memberText}>
                    {staff ? <Link href={`/dashboard/users/${p.id}`} className="a"><strong>{personName(p)}</strong></Link> : <strong>{personName(p)}</strong>}
                    <span>{[p.phone, m?.joined_at ? `${fmtDay(m.joined_at)} dan` : null].filter(Boolean).join(" · ")}</span>
                    {statusBadge(m) && <span className={s.memberBadge}>{statusBadge(m)}</span>}
                  </div>
                  {r && <div className={s.rate} title="Davomat"><Progress value={Number(r.rate)} label="Davomat" /><b>{r.rate}%</b></div>}
                  {manage ? (
                    <Dropdown
                      trigger={(t) => <Button size="sm" variant="ghost" iconOnly aria-label={`${personName(p)} — amallar`} icon={<MoreHorizontal />} {...t} />}
                      items={[
                        { label: "Profil", icon: <UserRound />, href: `/dashboard/users/${p.id}` },
                        { label: "A'zolik tarixi", icon: <History />, onSelect: () => openHistory(p) },
                        { type: "separator" },
                        frozenOrPlanned
                          ? { label: m?.status === "FROZEN" ? "Muzlatishni tugatish" : "Muzlatishni bekor qilish", icon: <PlayCircle />, onSelect: () => m && unfreeze(m) }
                          : { label: "Muzlatish", icon: <PauseCircle />, onSelect: () => freeze(p) },
                        { label: "Boshqa guruhga o'tkazish", icon: <ArrowRightLeft />, onSelect: () => transfer(p) },
                        { label: "Bitirdi", icon: <GraduationCap />, onSelect: () => graduate(p) },
                        { type: "separator" },
                        { label: "Guruhdan chiqarish", icon: <UserMinus />, danger: true, onSelect: () => leave(p) },
                      ]}
                    />
                  ) : staff ? (
                    <Button size="sm" variant="ghost" iconOnly aria-label={`${personName(p)} — a'zolik tarixi`} icon={<History />} onClick={() => openHistory(p)} />
                  ) : null}
                </li>
              );
            })}
          </ul>
        )}
      </Card>

      {staff && former.length > 0 && (
        <Card style={{ marginTop: 16 }}>
          <CardHeader
            title="Chiqib ketganlar"
            description={`${former.length} ta · davomat va to'lov tarixi saqlangan`}
            actions={<Button size="sm" variant="ghost" aria-expanded={showFormer} onClick={() => setShowFormer((x) => !x)}>{showFormer ? "Yashirish" : "Ko'rsatish"}</Button>}
          />
          {showFormer && (
            <ul className={s.members}>
              {former.map((f) => (
                <li key={f.id} className={cx(s.member, s.former)}>
                  <Avatar name={personName(f)} />
                  <div className={s.memberText}>
                    <Link href={`/dashboard/users/${f.id}`} className="a"><strong>{personName(f)}</strong></Link>
                    <span>{fmtDay(f.joined_at)} — {fmtDay(f.left_on)}{f.reason ? ` · ${f.reason}` : ""}</span>
                  </div>
                  <span className={s.memberSide}>
                    <Badge tone={ENROLLMENT_TONE[f.status] ?? "neutral"}>{labels[f.status] ?? f.status}</Badge>
                    {f.transfer_group && <Link href={`/dashboard/groups/${f.transfer_group.id}`} className="a subtle">→ {f.transfer_group.name}</Link>}
                  </span>
                  <Button size="sm" variant="ghost" iconOnly aria-label={`${personName(f)} — a'zolik tarixi`} icon={<History />} onClick={() => openHistory(f)} />
                </li>
              ))}
            </ul>
          )}
        </Card>
      )}

      {manage && <BulkAddDialog open={adding} onClose={() => setAdding(false)} groupId={groupId} memberIds={new Set(students.map((m) => m.id))} onAdded={(n) => done(`${n} ta o'quvchi qo'shildi`)} />}

      <Dialog open={history !== null} onClose={() => setHistory(null)} title="A'zolik tarixi" description={history ? personName(history.person) : undefined} icon={<History />} width={560}>
        {history && (history.rows === null ? <p className="subtle" style={{ margin: 0 }}>Yuklanmoqda…</p> : <Timeline rows={history.rows} labels={labels} />)}
      </Dialog>
    </>
  );
}

/** A'zolik tarixi (yangilari yuqorida). Profil sahifasi ham ishlatadi. */
export function Timeline({ rows, labels, showGroup }: { rows: TimelineRow[]; labels: Record<string, string>; showGroup?: boolean }) {
  if (!rows.length) return <EmptyState compact icon={<History />} title="Tarix yo'q" />;
  return (
    <ol className={s.timeline}>
      {rows.map((r) => {
        const state = r.outcome ?? r.status;
        return (
          <li key={r.id}>
            <span className={s.dot} data-tone={ENROLLMENT_TONE[state] ?? "neutral"} aria-hidden />
            <div>
              <div className={s.tlHead}>
                <Badge tone={ENROLLMENT_TONE[r.status] ?? "neutral"}>{labels[r.status] ?? r.status}</Badge>
                <span className="mono">{fmtDay(r.starts_on)} — {r.ends_on ? fmtDay(r.ends_on) : "hozir"}</span>
                {showGroup && <Link href={`/dashboard/groups/${r.group_id}`} className="a">{r.group_name}</Link>}
              </div>
              {(r.outcome || r.reason || r.note) && (
                <p className={s.tlText}>
                  {r.outcome && <b>{labels[r.outcome] ?? r.outcome}{r.transfer_group ? ` → ${r.transfer_group.name}` : ""}. </b>}
                  {r.reason && <>{r.reason}. </>}
                  {r.note && <span className="subtle">{r.note}</span>}
                </p>
              )}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

function BulkAddDialog({ open, onClose, groupId, memberIds, onAdded }: {
  open: boolean; onClose: () => void; groupId: number; memberIds: Set<number>; onAdded: (n: number) => void;
}) {
  const st = useSiteSettings();
  const toast = useToast();
  const [q, setQ] = useState("");
  const [results, setResults] = useState<Person[] | null>(null);
  const [picked, setPicked] = useState<Map<number, Person>>(new Map());
  const [date, setDate] = useState(() => todayIso(timezone(st)));
  const [busy, setBusy] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => { if (!open) { setPicked(new Map()); setQ(""); } }, [open]);
  useEffect(() => {
    if (!open) return;
    if (timer.current) clearTimeout(timer.current);
    const ctrl = new AbortController();
    timer.current = setTimeout(async () => {
      const r = await call<{ data: Person[] }>("GET", `users?role=STUDENT&active=1&per_page=50${q.trim() ? `&q=${encodeURIComponent(q.trim())}` : ""}`, undefined, { signal: ctrl.signal }).catch(() => null);
      if (r?.ok) setResults(r.data.data ?? []);
    }, 250);
    return () => { ctrl.abort(); if (timer.current) clearTimeout(timer.current); };
  }, [q, open]);

  const toggle = (p: Person) => setPicked((m) => { const n = new Map(m); if (n.has(p.id)) n.delete(p.id); else n.set(p.id, p); return n; });
  const selectable = (results ?? []).filter((p) => !memberIds.has(p.id));
  const allPicked = selectable.length > 0 && selectable.every((p) => picked.has(p.id));

  async function submit() {
    if (!picked.size) return;
    setBusy(true);
    const r = await call<{ added: number; already: number; errors: { message: string }[] }>("POST", `groups/${groupId}/members/bulk`, { student_ids: [...picked.keys()], start_date: date || null });
    setBusy(false);
    if (!r.ok) return toast.error("Qo'shilmadi", errorText(r.data));
    onAdded(r.data.added);
    onClose();
  }

  return (
    <Dialog
      open={open} onClose={onClose} title="O'quvchilarni qo'shish" description="Bir nechtasini belgilab, birdaniga qo'shing" icon={<UserPlus />} width={560}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>Bekor qilish</Button>
          <Button icon={<UserPlus />} disabled={!picked.size} loading={busy} onClick={submit}>{picked.size ? `${picked.size} ta qo'shish` : "Qo'shish"}</Button>
        </>
      }
    >
      <div className={s.bulkTop}>
        <Input type="search" autoFocus placeholder="Ism yoki telefon: Aziz, 90 123" value={q} onChange={(e) => setQ(e.target.value)} leading={<Search />} aria-label="O'quvchini qidirish" />
        <Field label="Qaysi kundan" hint="To'lov shu kundan hisoblanadi"><Input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></Field>
      </div>
      {results === null ? <p className="subtle" style={{ margin: 0 }}>Yuklanmoqda…</p> : results.length === 0 ? (
        <EmptyState compact icon={<Search />} title="Topilmadi">
          O&apos;quvchini «Foydalanuvchilar» bo&apos;limida qo&apos;shing yoki <Link href={`/dashboard/users?import=1&group=${groupId}`} className="a">Excel&apos;dan import qiling</Link>.
        </EmptyState>
      ) : (
        <>
          {selectable.length > 1 && (
            <Checkbox label={`Ro'yxatdagilarning hammasi (${selectable.length})`} checked={allPicked}
              onChange={() => setPicked((m) => { const n = new Map(m); if (allPicked) selectable.forEach((p) => n.delete(p.id)); else selectable.forEach((p) => n.set(p.id, p)); return n; })} />
          )}
          <ul className={s.results}>
            {results.map((p) => {
              const isMember = memberIds.has(p.id);
              return (
                <li key={p.id}>
                  <label className={s.pick} data-disabled={isMember || undefined}>
                    <input type="checkbox" disabled={isMember} checked={isMember || picked.has(p.id)} onChange={() => toggle(p)} />
                    <Avatar name={personName(p)} />
                    <span style={{ flex: 1, minWidth: 0 }}><strong>{personName(p)}</strong><br /><span className="subtle">{p.phone}</span></span>
                    {isMember && <Badge tone="success" icon={<CalendarCheck size={14} aria-hidden />}>Guruhda</Badge>}
                  </label>
                </li>
              );
            })}
          </ul>
        </>
      )}
    </Dialog>
  );
}
