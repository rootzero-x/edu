"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { CheckCircle2, ExternalLink, MoreHorizontal, NotebookPen, Pencil, Save, Send, Trash2 } from "lucide-react";
import { Badge, Button, Card, Dropdown, EmptyState, Field, Input, PageHeader, StatCard, Textarea, cx, useConfirm, useToast } from "@/components/ui";
import AssignmentDialog from "@/components/homework/AssignmentDialog";
import FileList from "@/components/files/FileList";
import FilePicker from "@/components/files/FilePicker";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText, upload } from "@/lib/client";
import { fmtDateTime } from "@/lib/format";
import { HW_DEFAULT_LABELS, HW_TONE, type Assignment, type Mine, type SubmissionRow } from "@/lib/homework";
import { bool, num, obj, timezone } from "@/lib/settings";
import s from "../homework.module.css";

export default function HomeworkDetail({ initial }: { initial: Assignment }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const router = useRouter();
  const toast = useToast();
  const confirm = useConfirm();
  const labels = { ...HW_DEFAULT_LABELS, ...obj<Record<string, string>>(st, "homework.status_labels") };
  const [a, setA] = useState(initial);
  const [editing, setEditing] = useState(false);

  async function reload() {
    const r = await call<{ assignment: Assignment }>("GET", `assignments/${a.id}`);
    if (r.ok) setA(r.data.assignment);
  }

  async function remove() {
    if (!(await confirm({ title: "Vazifa o'chirilsinmi?", description: a.title, confirmText: "O'chirish", tone: "danger" }))) return;
    const r = await call("DELETE", `assignments/${a.id}`);
    if (!r.ok) return toast.error("O'chirilmadi", errorText(r.data));
    toast.success("Vazifa o'chirildi");
    router.push("/dashboard/homework");
    router.refresh();
  }

  const overdue = a.due_at ? new Date(a.due_at).getTime() < Date.now() : false;

  return (
    <>
      <PageHeader
        back={{ href: "/dashboard/homework", label: "Uyga vazifa" }}
        title={a.title}
        badge={a.my && <Badge tone={HW_TONE[a.my.status]} dot>{labels[a.my.status]}</Badge>}
        description={[a.group_name, a.course_title].filter(Boolean).join(" · ")}
        actions={a.can_manage && (
          <Dropdown trigger={(p) => <Button variant="secondary" iconOnly aria-label="Vazifa amallari" icon={<MoreHorizontal />} {...p} />}
            items={[
              { label: "Tahrirlash", icon: <Pencil />, onSelect: () => setEditing(true) },
              { type: "separator" },
              { label: "O'chirish", icon: <Trash2 />, danger: true, onSelect: remove },
            ]} />
        )}
      />

      <div className={s.detail}>
        <div style={{ display: "grid", gap: "var(--space-4)", minWidth: 0 }}>
          <Card className={s.section}>
            <h2>Topshiriq</h2>
            {a.body ? <p className={s.body}>{a.body}</p> : <p className="subtle" style={{ margin: 0 }}>Matn yo&apos;q — fayllarni ko&apos;ring.</p>}
            <FileList files={a.files} canDelete={a.can_manage} onDeleted={(id) => setA({ ...a, files: a.files.filter((f) => f.id !== id) })} />
          </Card>
          {a.can_manage ? <Grading a={a} onSaved={setA} labels={labels} tz={tz} /> : a.my && <MySubmission a={a} mine={a.my} onSaved={(my) => setA({ ...a, my })} tz={tz} />}
        </div>

        <Card className={s.section}>
          <h2>Ma&apos;lumot</h2>
          <dl className={s.facts}>
            <div><dt>Guruh</dt><dd><Link className="a" href={`/dashboard/groups/${a.group_id}`}>{a.group_name}</Link></dd></div>
            <div><dt>Muddat</dt><dd className={overdue ? s.overdue : undefined}>{a.due_at ? fmtDateTime(a.due_at, tz) : "Muddatsiz"}</dd></div>
            <div><dt>Maksimal ball</dt><dd>{a.max_score}</dd></div>
            {a.session_id && a.session_starts_at && <div><dt>Mashg&apos;ulot</dt><dd><Link className="a" href={`/dashboard/sessions/${a.session_id}`}>{fmtDateTime(a.session_starts_at, tz)}</Link></dd></div>}
            {a.lesson_id && <div><dt>Dars</dt><dd><Link className="a" href={`/dashboard/lessons/${a.lesson_id}`}>{a.lesson_title}</Link></dd></div>}
            {a.author && <div><dt>Bergan</dt><dd>{a.author}</dd></div>}
          </dl>
          {a.stats && (
            <div className="stats-grid" style={{ marginBottom: 0, gridTemplateColumns: "1fr 1fr" }}>
              <StatCard label="Topshirdi" value={`${a.stats.submitted}/${a.stats.total}`} progress={a.stats.total ? (a.stats.submitted / a.stats.total) * 100 : 0} />
              <StatCard label="Baholandi" value={a.stats.graded} hint={a.stats.late ? `${a.stats.late} ta kechikkan` : undefined} tone="success" />
            </div>
          )}
        </Card>
      </div>
      {a.can_manage && <AssignmentDialog open={editing} onClose={() => setEditing(false)} assignment={a} onSaved={() => void reload()} />}
    </>
  );
}

/** O'qituvchi: har bir o'quvchi javobi, ball va izoh — o'zgarganlar bitta "Saqlash" bilan. */
function Grading({ a, onSaved, labels, tz }: { a: Assignment; onSaved: (a: Assignment) => void; labels: Record<string, string>; tz: string }) {
  const toast = useToast();
  const rows = a.submissions ?? [];
  const [edits, setEdits] = useState<Record<number, { score: string; feedback: string }>>({});
  const [busy, setBusy] = useState(false);
  const value = (r: SubmissionRow) => edits[r.student_id] ?? { score: r.score == null ? "" : String(r.score), feedback: r.feedback ?? "" };
  const dirty = useMemo(() => rows.filter((r) => {
    const e = edits[r.student_id];
    return e && (e.score !== (r.score == null ? "" : String(r.score)) || e.feedback !== (r.feedback ?? ""));
  }), [rows, edits]);
  const set = (r: SubmissionRow, patch: Partial<{ score: string; feedback: string }>) => setEdits((x) => ({ ...x, [r.student_id]: { ...value(r), ...patch } }));

  async function save() {
    const bad = dirty.find((r) => { const v = value(r).score; return v !== "" && (!/^\d+$/.test(v) || +v > a.max_score); });
    if (bad) return toast.error("Ball noto'g'ri", `${bad.name}: 0 dan ${a.max_score} gacha butun son.`);
    setBusy(true);
    const r = await call<{ submissions: SubmissionRow[] }>("PUT", `assignments/${a.id}/grades`, {
      items: dirty.map((x) => ({ student_id: x.student_id, score: value(x).score === "" ? null : Number(value(x).score), feedback: value(x).feedback.trim() || null })),
    });
    setBusy(false);
    if (!r.ok) return toast.error("Saqlanmadi", errorText(r.data));
    toast.success("Baholar saqlandi", `${dirty.length} ta o'quvchi`);
    setEdits({});
    const subs = r.data.submissions;
    onSaved({ ...a, submissions: subs, stats: a.stats && { ...a.stats, graded: subs.filter((x) => x.score != null && !x.left_group).length } });
  }

  if (rows.length === 0) return <Card><EmptyState compact icon={<NotebookPen />} title="Guruhda o'quvchi yo'q" /></Card>;
  return (
    <Card>
      <div className={cx(s.gradeRow, s.gradeHead)} aria-hidden><span>O&apos;quvchi</span><span>Javob</span><span>Ball</span><span>Izoh</span></div>
      {rows.map((r) => {
        const v = value(r);
        const isDirty = dirty.includes(r);
        return (
          <div key={r.student_id} className={cx(s.gradeRow, isDirty && s.dirty)}>
            <div className={s.who}>
              <b>{r.name}</b>
              <span><Badge tone={HW_TONE[r.status]} dot>{labels[r.status]}</Badge>{r.left_group && <> <Badge>Guruhdan chiqqan</Badge></>}</span>
              {r.submitted_at && <span className="subtle" style={{ fontSize: "var(--text-xs)" }}>{fmtDateTime(r.submitted_at, tz)}</span>}
            </div>
            <div style={{ minWidth: 0 }}>
              {r.body && <p className={s.answer}>{r.body}</p>}
              {r.link_url && <a className="a" href={r.link_url} target="_blank" rel="noopener noreferrer nofollow"><ExternalLink size={13} aria-hidden /> Havola</a>}
              <FileList files={r.files} />
              {!r.body && !r.link_url && r.files.length === 0 && <span className="subtle">—</span>}
            </div>
            <Field label={<span className="sr-only">{r.name} — ball</span>}>
              <Input type="number" inputMode="numeric" min={0} max={a.max_score} value={v.score} placeholder={`/${a.max_score}`} onChange={(e) => set(r, { score: e.target.value })} />
            </Field>
            <Field label={<span className="sr-only">{r.name} — izoh</span>}>
              <Textarea rows={1} value={v.feedback} maxLength={2000} placeholder="Izoh (ixtiyoriy)" onChange={(e) => set(r, { feedback: e.target.value })} />
            </Field>
          </div>
        );
      })}
      <div className={s.saveBar}>
        <span className="subtle">{dirty.length ? `${dirty.length} ta o'zgarish` : "O'zgarish yo'q"}</span>
        <Button icon={<Save />} loading={busy} disabled={!dirty.length} onClick={save}>Saqlash</Button>
      </div>
    </Card>
  );
}

/** O'quvchi: javob (matn, havola, fayllar) va natija. */
function MySubmission({ a, mine, onSaved, tz }: { a: Assignment; mine: Mine; onSaved: (m: Mine) => void; tz: string }) {
  const st = useSiteSettings();
  const toast = useToast();
  const [body, setBody] = useState(mine.body ?? "");
  const [link, setLink] = useState(mine.link_url ?? "");
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const studentFiles = bool(st, "uploads.student_files", true);
  const maxFiles = num(st, "uploads.max_files_per_item", 5);

  async function submit() {
    setBusy(true);
    setErr(null);
    // Yuklangan fayl darhol ro'yxatdan olinadi: keyingisi xato bersa, qayta urinishda u ikkinchi marta yuklanmaydi.
    for (const f of files) {
      const u = await upload(`assignments/${a.id}/submission/files`, f);
      if (!u.ok) { setBusy(false); return setErr(`${f.name}: ${errorText(u.data)}`); }
      setFiles((xs) => xs.filter((x) => x !== f));
    }
    const r = await call<{ my: Mine }>("PUT", `assignments/${a.id}/submission`, { body: body.trim() || null, link_url: link.trim() || null });
    setBusy(false);
    if (!r.ok) return setErr(errorText(r.data));
    setFiles([]);
    toast.success("Javob topshirildi");
    onSaved(r.data.my);
  }

  return (
    <>
      {mine.score != null && (
        <Card className={s.section}>
          <h2><CheckCircle2 size={18} aria-hidden style={{ verticalAlign: "-3px", color: "var(--success)" }} /> Natija</h2>
          <div className={s.avg}><span className={s.score}>{mine.score}<small>/{a.max_score}</small></span>{mine.graded_at && <span className="subtle">{fmtDateTime(mine.graded_at, tz)}</span>}</div>
          {mine.feedback && <p className={s.body}>{mine.feedback}</p>}
        </Card>
      )}
      <Card className={s.section}>
        <h2>Mening javobim</h2>
        {mine.submitted_at && <p className="subtle" style={{ margin: 0 }}>Topshirilgan: {fmtDateTime(mine.submitted_at, tz)}</p>}
        {mine.can_submit ? (
          <form onSubmit={(e) => { e.preventDefault(); void submit(); }} style={{ display: "grid", gap: "var(--space-3)" }}>
            <Field label="Javob matni"><Textarea rows={6} value={body} onChange={(e) => setBody(e.target.value)} maxLength={20000} /></Field>
            <Field label="Havola" hint="Masalan: Google Drive, GitHub"><Input type="url" inputMode="url" value={link} onChange={(e) => setLink(e.target.value)} placeholder="https://" /></Field>
            <FileList files={mine.files} canDelete onDeleted={(id) => onSaved({ ...mine, files: mine.files.filter((f) => f.id !== id) })} pending={files} />
            {studentFiles && <FilePicker onPick={(l) => setFiles((x) => [...x, ...l])} remaining={maxFiles - mine.files.length - files.length} />}
            {err && <p className="error" role="alert" style={{ margin: 0 }}>{err}</p>}
            <div><Button type="submit" icon={<Send />} loading={busy}>{mine.submitted_at ? "Qayta topshirish" : "Topshirish"}</Button></div>
          </form>
        ) : (
          <>
            {mine.body && <p className={s.answer}>{mine.body}</p>}
            {mine.link_url && <a className="a" href={mine.link_url} target="_blank" rel="noopener noreferrer nofollow"><ExternalLink size={13} aria-hidden /> {mine.link_url}</a>}
            <FileList files={mine.files} />
            {!mine.submitted_at && <p className="subtle" style={{ margin: 0 }}>Topshirish muddati o&apos;tgan.</p>}
          </>
        )}
      </Card>
    </>
  );
}
