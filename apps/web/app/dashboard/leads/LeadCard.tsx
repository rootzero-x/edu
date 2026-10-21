"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { CalendarClock, MessageSquare, Pencil, Phone, PhoneCall, Send, Trash2, UserCheck, UserPlus, XCircle } from "lucide-react";
import { Badge, Button, Dialog, EmptyState, Field, Input, Select, Textarea, useConfirm, usePrompt, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDay, todayIso } from "@/lib/enrollment";
import { fmtDate, fmtDateTime, fmtTime, fmtWeekday } from "@/lib/format";
import { LEAD_STATUS_FALLBACK, LEAD_TONE, OPEN_STATUSES, type Lead, type LeadActivity, type LeadOptions } from "@/lib/leads";
import { list, obj, timezone } from "@/lib/settings";
import type { GroupRow } from "@/lib/types";
import s from "./leads.module.css";

type Detail = {
  lead: Lead; activities: LeadActivity[];
  existing_user: { id: number; name: string; role: string } | null;
  other_leads: { id: number; status: string; source: string; created_at: string }[];
};
type TrialSession = { id: number; group_id: number; group_name: string; room: string | null; teacher: string; starts_at: string };

/** Ariza kartochkasi: qo'ng'iroq/izoh, holat, sinov darsi, rad etish, o'quvchiga aylantirish va tarix. */
export default function LeadCard({ id, options, onClose, onChanged }: { id: number | null; options: LeadOptions; onClose: () => void; onChanged: () => void }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const toast = useToast();
  const prompt = usePrompt();
  const confirm = useConfirm();
  const statusLabels = { ...LEAD_STATUS_FALLBACK, ...obj<Record<string, string>>(st, "leads.status_labels") };
  const sources = obj<Record<string, string>>(st, "leads.sources");
  const roles = obj<Record<string, string>>(st, "telegram.role_labels");
  const [d, setD] = useState<Detail | null>(null);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [converting, setConverting] = useState(false);

  // Ota komponent har renderda yangi onClose/toast berishi mumkin — ular ref orqali olinadi, aks holda kartochka
  // har o'zgarishda "Yuklanmoqda…" holatiga qaytib, so'rovlar takrorlanardi. Yuklash faqat id o'zgarganda.
  const closeRef = useRef(onClose);
  closeRef.current = onClose;
  const toastRef = useRef(toast);
  toastRef.current = toast;
  const load = useCallback(async () => {
    if (!id) return;
    const r = await call<Detail>("GET", `leads/${id}`);
    if (r.ok) setD(r.data);
    else { toastRef.current.error("Ariza topilmadi", errorText(r.data)); closeRef.current(); }
  }, [id]);
  useEffect(() => { setD(null); setNote(""); setConverting(false); void load(); }, [load]);

  const changed = () => { void load(); onChanged(); };
  const lead = d?.lead;
  const open = lead ? OPEN_STATUSES.includes(lead.status) : false;

  async function addActivity(type: "CALL" | "NOTE") {
    if (!lead || !note.trim() || busy) return;
    setBusy(true);
    const r = await call("POST", `leads/${lead.id}/activities`, { type, text: note.trim() });
    setBusy(false);
    if (!r.ok) return toast.error("Saqlanmadi", errorText(r.data));
    setNote("");
    toast.success(type === "CALL" ? "Qo'ng'iroq qayd etildi" : "Izoh qo'shildi");
    changed();
  }

  const setStatus = async (status: string, extra: Record<string, unknown> = {}) => {
    if (!lead || busy) return "";
    setBusy(true);
    const r = await call("POST", `leads/${lead.id}/status`, { status, ...extra }).finally(() => setBusy(false));
    if (!r.ok) return errorText(r.data);
    toast.success(statusLabels[status] ?? status);
    changed();
    return null;
  };

  async function trial() {
    if (!lead) return;
    const r = await call<{ data: TrialSession[] }>("GET", `leads/trial-sessions${lead.course ? `?course_id=${lead.course.id}` : ""}`);
    const sessions = r.ok ? r.data.data ?? [] : [];
    if (!sessions.length) return toast.info("Yaqin 14 kunda mos dars yo'q", lead.course ? "Kurs bo'yicha guruh jadvalini tekshiring" : "Avval kursni belgilang");
    await prompt({
      title: "Sinov darsiga yozish",
      description: "Darsdan keyingi kuni qayta bog'lanish eslatiladi.",
      fields: [
        { name: "trial_session_id", label: "Dars", type: "select", required: true, options: [{ value: "", label: "— tanlang —" },
          ...sessions.map((x) => ({ value: String(x.id), label: `${fmtDate(x.starts_at, tz)} ${fmtWeekday(x.starts_at, tz)} ${fmtTime(x.starts_at, tz)} · ${x.group_name}${x.teacher ? ` · ${x.teacher}` : ""}` }))] },
        { name: "note", label: "Izoh", type: "textarea" },
      ],
      submitText: "Yozish",
      onSubmit: (v) => setStatus("TRIAL", { trial_session_id: Number(v.trial_session_id), note: v.note || null }),
    });
  }

  const lose = () => prompt({
    title: "Rad etdi",
    fields: [
      { name: "lost_reason", label: "Sabab", type: "select", required: true, options: [{ value: "", label: "— tanlang —" }, ...list<string>(st, "leads.lost_reasons").map((x) => ({ value: x, label: x }))] },
      { name: "note", label: "Izoh", type: "textarea" },
    ],
    submitText: "Saqlash",
    onSubmit: (v) => setStatus("LOST", { lost_reason: v.lost_reason, note: v.note || null }),
  });

  const edit = () => lead && prompt({
    title: "Arizani tahrirlash",
    fields: [
      { name: "name", label: "Ism", required: true, defaultValue: lead.name },
      { name: "phone", label: "Telefon", type: "tel", required: true, defaultValue: lead.phone },
      { name: "course_id", label: "Kurs", type: "select", defaultValue: lead.course ? String(lead.course.id) : "", options: [{ value: "", label: "— bilmaydi —" }, ...options.courses.map((c) => ({ value: String(c.id), label: c.title }))] },
      { name: "source", label: "Manba", type: "select", required: true, defaultValue: lead.source, options: Object.entries(sources).map(([value, label]) => ({ value, label })) },
      { name: "owner_id", label: "Mas'ul xodim", type: "select", defaultValue: lead.owner ? String(lead.owner.id) : "", options: [{ value: "", label: "— tayinlanmagan —" }, ...options.data.map((o) => ({ value: String(o.id), label: o.name }))] },
      { name: "next_contact_on", label: "Keyingi bog'lanish", type: "date", defaultValue: lead.next_contact_on ?? "" },
      { name: "notes", label: "Xodim izohi", type: "textarea", defaultValue: lead.notes ?? "" },
    ],
    onSubmit: async (v) => {
      const r = await call("PATCH", `leads/${lead.id}`, { ...v, course_id: v.course_id ? Number(v.course_id) : null, owner_id: v.owner_id ? Number(v.owner_id) : null, next_contact_on: v.next_contact_on || null, notes: v.notes || null });
      if (!r.ok) return errorText(r.data);
      toast.success("Saqlandi");
      changed();
    },
  });

  async function remove() {
    if (!lead || !(await confirm({ title: "Ariza o'chirilsinmi?", description: "Faqat spam yoki xato kiritilgan arizalar uchun. Rad etganlarni «Rad etdi» deb belgilang — ular voronkada hisobga olinadi.", confirmText: "O'chirish", tone: "danger" }))) return;
    const r = await call("DELETE", `leads/${lead.id}`);
    if (!r.ok) return toast.error("O'chirilmadi", errorText(r.data));
    toast.success("Ariza o'chirildi");
    onChanged();
    onClose();
  }

  const activityText = (a: LeadActivity) => {
    const data = a.data ?? {};
    if (a.type === "STATUS") return `${statusLabels[String(data.from)] ?? data.from ?? ""} → ${statusLabels[String(data.to)] ?? data.to}${data.lost_reason ? ` (${data.lost_reason})` : ""}`;
    if (a.type === "SYSTEM") {
      if (data.event === "created") return `Ariza qabul qilindi · ${sources[String(data.source)] ?? data.source}`;
      if (data.event === "duplicate") return `Takroriy ariza · ${sources[String(data.source)] ?? data.source}`;
      if (data.event === "updated") return `Tahrirlandi${data.next_contact_on !== undefined ? ` · bog'lanish: ${fmtDay(String(data.next_contact_on ?? ""))}` : ""}`;
    }
    return "";
  };

  return (
    <Dialog open={id !== null} onClose={onClose} width={760} icon={<UserCheck />}
      title={lead ? lead.name : "Ariza"}
      description={lead ? <>{sources[lead.source] ?? lead.source} · {fmtDateTime(lead.created_at, tz)}</> : "Yuklanmoqda…"}>
      {!d || !lead ? <p className="subtle" style={{ margin: 0 }}>Yuklanmoqda…</p> : converting ? (
        <ConvertForm lead={lead} onCancel={() => setConverting(false)} onDone={() => { setConverting(false); changed(); }} />
      ) : (
        <div className={s.card}>
          <div className={s.cardTop}>
            <a href={`tel:${lead.phone}`} className={s.phone}><Phone aria-hidden />{lead.phone}</a>
            <Badge tone={LEAD_TONE[lead.status] ?? "neutral"}>{statusLabels[lead.status] ?? lead.status}</Badge>
            {lead.telegram && <Badge icon={<Send size={12} aria-hidden />}>Telegram</Badge>}
            <span className={s.grow} />
            <Button size="sm" variant="ghost" icon={<Pencil />} onClick={edit}>Tahrirlash</Button>
          </div>
          <dl className={s.facts}>
            <div><dt>Kurs</dt><dd>{lead.course?.title ?? "—"}</dd></div>
            {lead.group && <div><dt>Guruh</dt><dd><Link href={`/dashboard/groups/${lead.group.id}`} className="a">{lead.group.name}</Link></dd></div>}
            <div><dt>Mas&apos;ul</dt><dd>{lead.owner?.name ?? "—"}</dd></div>
            <div><dt>Keyingi bog&apos;lanish</dt><dd>{lead.next_contact_on ? fmtDay(lead.next_contact_on) : "—"}</dd></div>
            {lead.trial && <div><dt>Sinov darsi</dt><dd>{fmtDateTime(lead.trial.starts_at, tz)} · <Link href={`/dashboard/groups/${lead.trial.group_id}`} className="a">{lead.trial.group_name}</Link>{lead.trial.cancelled && " (bekor)"}</dd></div>}
            {lead.lost_reason && <div><dt>Rad etish sababi</dt><dd>{lead.lost_reason}</dd></div>}
            {lead.student_id && <div><dt>O&apos;quvchi</dt><dd><Link href={`/dashboard/users/${lead.student_id}`} className="a">Profilni ochish</Link></dd></div>}
          </dl>
          {lead.comment && <blockquote className={s.quote}>{lead.comment}</blockquote>}
          {lead.notes && <p className={s.notes}><b>Izoh:</b> {lead.notes}</p>}
          {d.existing_user && !lead.student_id && (
            <p className={s.hint}>Bu raqam bazada bor: <Link href={`/dashboard/users/${d.existing_user.id}`} className="a">{d.existing_user.name}</Link> ({roles[d.existing_user.role] ?? d.existing_user.role}).</p>
          )}
          {d.other_leads.length > 0 && <p className="subtle" style={{ margin: 0 }}>Shu raqamdan avvalgi arizalar: {d.other_leads.map((o) => `#${o.id} (${statusLabels[o.status] ?? o.status})`).join(", ")}</p>}

          {open && (
            <div className={s.actions}>
              {lead.status === "NEW" && <Button size="sm" variant="secondary" icon={<PhoneCall />} disabled={busy} onClick={() => setStatus("CONTACTED")}>Bog&apos;lanildi</Button>}
              <Button size="sm" variant="secondary" icon={<CalendarClock />} onClick={trial}>Sinov darsi</Button>
              <Button size="sm" icon={<UserPlus />} onClick={() => setConverting(true)}>O&apos;quvchiga aylantirish</Button>
              <Button size="sm" variant="dangerGhost" icon={<XCircle />} onClick={lose}>Rad etdi</Button>
            </div>
          )}
          {!open && lead.status === "LOST" && <div className={s.actions}><Button size="sm" variant="secondary" disabled={busy} onClick={() => setStatus("CONTACTED")}>Qayta ochish</Button></div>}

          <div className={s.compose}>
            <Field label="Qo'ng'iroq natijasi yoki izoh">
              <Textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Masalan: Kechqurun 18:00 dan keyin qo'ng'iroq qiling" />
            </Field>
            <div className={s.composeBtns}>
              <Button size="sm" variant="secondary" icon={<PhoneCall />} disabled={!note.trim()} loading={busy} onClick={() => addActivity("CALL")}>Qo&apos;ng&apos;iroq qayd etish</Button>
              <Button size="sm" variant="ghost" icon={<MessageSquare />} disabled={!note.trim() || busy} onClick={() => addActivity("NOTE")}>Izoh</Button>
            </div>
          </div>

          <h3 className={s.h3}>Tarix</h3>
          {d.activities.length === 0 ? <EmptyState compact icon={<MessageSquare />} title="Tarix bo'sh" /> : (
            <ol className={s.feed}>
              {d.activities.map((a) => (
                <li key={a.id} data-type={a.type}>
                  <span className={s.feedWhen}>{fmtDateTime(a.created_at, tz)}{a.user ? ` · ${a.user}` : ""}</span>
                  <span>
                    {a.type === "CALL" && <Badge tone="accent" icon={<PhoneCall size={12} aria-hidden />}>Qo&apos;ng&apos;iroq</Badge>}{" "}
                    {activityText(a)}{a.text && <span className={s.feedText}>{a.text}</span>}
                  </span>
                </li>
              ))}
            </ol>
          )}
          <div className={s.dangerZone}><Button size="sm" variant="dangerGhost" icon={<Trash2 />} onClick={remove}>Arizani o&apos;chirish</Button></div>
        </div>
      )}
    </Dialog>
  );
}

/** Arizani o'quvchiga aylantirish: o'quvchi akkaunti + guruh — bitta qadamda. */
function ConvertForm({ lead, onCancel, onDone }: { lead: Lead; onCancel: () => void; onDone: () => void }) {
  const st = useSiteSettings();
  const toast = useToast();
  const [first, ...rest] = lead.name.split(/\s+/);
  const [form, setForm] = useState({
    first_name: first ?? "", last_name: rest.join(" "), phone: lead.phone, group_id: lead.group ? String(lead.group.id) : "",
    start_date: todayIso(timezone(st)),
  });
  const [groups, setGroups] = useState<GroupRow[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    void call<{ data: GroupRow[] }>("GET", "groups").then((r) => {
      if (!r.ok) return;
      const active = (r.data.data ?? []).filter((g) => g.active);
      // Arizadagi kursning guruhlari birinchi.
      setGroups(active.sort((a, b) => Number(b.course?.id === lead.course?.id) - Number(a.course?.id === lead.course?.id) || a.name.localeCompare(b.name)));
    });
  }, [lead.course?.id]);
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm({ ...form, [k]: e.target.value });

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true); setError(null);
    const r = await call<{ created: boolean; joined: string | null }>("POST", `leads/${lead.id}/convert`, {
      ...form, last_name: form.last_name || null, group_id: form.group_id ? Number(form.group_id) : null, start_date: form.group_id ? form.start_date : null,
    });
    setBusy(false);
    if (!r.ok) return setError(errorText(r.data));
    toast.success("O'quvchi yozildi", `${form.first_name}${r.data.created ? " — yangi akkaunt" : " — mavjud akkaunt"}${r.data.joined && r.data.joined !== "already" ? " · guruhga qo'shildi" : ""}`);
    onDone();
  }

  return (
    <form className={s.convert} onSubmit={submit}>
      <p className="subtle" style={{ margin: 0 }}>Ariza boshqa odamdan (masalan, ota-onadan) bo&apos;lsa, o&apos;quvchining ismi va raqamini kiriting — raqam bazada bo&apos;lsa, o&apos;sha akkaunt ishlatiladi.</p>
      <div className={s.grid2}>
        <Field label="Ism" required><Input value={form.first_name} onChange={set("first_name")} /></Field>
        <Field label="Familiya"><Input value={form.last_name} onChange={set("last_name")} /></Field>
        <Field label="O'quvchi telefoni" required hint="Bazada bo'lsa — o'sha akkaunt ishlatiladi"><Input type="tel" value={form.phone} onChange={set("phone")} /></Field>
        <Field label="Guruh">
          <Select value={form.group_id} onChange={set("group_id")}>
            <option value="">— keyinroq —</option>
            {groups.map((g) => <option key={g.id} value={g.id}>{g.name}{g.course ? ` · ${g.course.title}` : ""}</option>)}
          </Select>
        </Field>
        {form.group_id && <Field label="Qaysi kundan" hint="To'lov shu kundan hisoblanadi"><Input type="date" value={form.start_date} onChange={set("start_date")} /></Field>}
      </div>
      {error && <p role="alert" className={s.error}>{error}</p>}
      <div className={s.composeBtns}>
        <Button variant="ghost" onClick={onCancel}>Orqaga</Button>
        <Button type="submit" icon={<UserPlus />} loading={busy}>Yozish</Button>
      </div>
    </form>
  );
}
