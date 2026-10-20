"use client";

import { useEffect, useState } from "react";
import { NotebookPen } from "lucide-react";
import { Button, Dialog, Field, Input, Select, Switch, Textarea, useToast } from "@/components/ui";
import FilePicker from "@/components/files/FilePicker";
import FileList from "@/components/files/FileList";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText, upload } from "@/lib/client";
import { fromLocalInput, toLocalInput, type Assignment } from "@/lib/homework";
import { num, timezone } from "@/lib/settings";

type GroupOpt = { id: number; name: string };

/**
 * Vazifa berish / tahrirlash. Guruh berilmasa — ro'yxatdan tanlanadi.
 * Fayllar vazifa yaratilgandan keyin yuklanadi (vazifa id si kerak).
 */
export default function AssignmentDialog({ open, onClose, onSaved, groups, groupId, sessionId, assignment }: {
  open: boolean; onClose: () => void; onSaved: (a: Assignment) => void;
  groups?: GroupOpt[]; groupId?: number; sessionId?: number; assignment?: Assignment;
}) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const toast = useToast();
  const maxFiles = num(st, "uploads.max_files_per_item", 5);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [files, setFiles] = useState<File[]>([]);
  const [f, setF] = useState({ group_id: "", title: "", body: "", due_at: "", max_score: "", notify: true });

  useEffect(() => {
    if (!open) return;
    setErr(null);
    setFiles([]);
    setF({
      group_id: String(assignment?.group_id ?? groupId ?? (groups?.length === 1 ? groups[0].id : "")),
      title: assignment?.title ?? "", body: assignment?.body ?? "",
      due_at: assignment ? toLocalInput(assignment.due_at, tz) : "",
      max_score: assignment && assignment.max_score !== num(st, "marks.scale_max", 5) ? String(assignment.max_score) : "",
      notify: true,
    });
  }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

  async function save() {
    if (!f.group_id) return setErr("Guruhni tanlang.");
    setBusy(true);
    setErr(null);
    const body = {
      title: f.title.trim(), body: f.body.trim() || null, due_at: fromLocalInput(f.due_at, tz),
      max_score: f.max_score ? Number(f.max_score) : null,
      ...(assignment ? {} : { session_id: sessionId ?? null, notify: f.notify }),
    };
    const r = assignment
      ? await call<{ assignment: Assignment }>("PATCH", `assignments/${assignment.id}`, body)
      : await call<{ assignment: Assignment }>("POST", `groups/${f.group_id}/assignments`, body);
    if (!r.ok) { setBusy(false); return setErr(errorText(r.data)); }
    const saved = r.data.assignment;
    let failed = 0;
    for (const file of files) {
      const u = await upload(`assignments/${saved.id}/files`, file);
      if (!u.ok) { failed++; toast.error(file.name, errorText(u.data)); }
    }
    setBusy(false);
    toast.success(assignment ? "Vazifa saqlandi" : "Vazifa berildi", failed ? `${failed} ta fayl yuklanmadi` : undefined);
    onSaved(saved);
    onClose();
  }

  return (
    <Dialog open={open} onClose={onClose} title={assignment ? "Vazifani tahrirlash" : "Uyga vazifa berish"} icon={<NotebookPen />} width={600} onSubmit={save}
      footer={<><Button variant="secondary" onClick={onClose}>Bekor qilish</Button><Button type="submit" loading={busy}>{assignment ? "Saqlash" : "Berish"}</Button></>}>
      {!assignment && !groupId && groups && (
        <Field label="Guruh" required>
          <Select value={f.group_id} onChange={(e) => setF({ ...f, group_id: e.target.value })}>
            <option value="">Tanlang…</option>{groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </Select>
        </Field>
      )}
      <Field label="Sarlavha" required><Input value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} maxLength={200} autoFocus placeholder="Masalan: 12-dars, 1–10 mashqlar" /></Field>
      <Field label="Topshiriq matni"><Textarea rows={5} value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} maxLength={20000} /></Field>
      <div className="cols">
        <Field label="Muddat" hint="Bo'sh — muddatsiz"><Input type="datetime-local" value={f.due_at} onChange={(e) => setF({ ...f, due_at: e.target.value })} /></Field>
        <Field label="Maksimal ball" hint={`Bo'sh — ${num(st, "marks.scale_max", 5)} ballik shkala`}><Input type="number" min={1} max={1000} value={f.max_score} onChange={(e) => setF({ ...f, max_score: e.target.value })} /></Field>
      </div>
      {assignment?.files.length ? <FileList files={assignment.files} /> : null}
      <FileList files={[]} pending={files} />
      <FilePicker onPick={(list) => setFiles((x) => [...x, ...list])} remaining={maxFiles - files.length - (assignment?.files.length ?? 0)} />
      {!assignment && <Switch checked={f.notify} onChange={(e) => setF({ ...f, notify: e.target.checked })} label="O'quvchilarga Telegram orqali xabar yuborish" />}
      {err && <p className="error" role="alert" style={{ margin: 0 }}>{err}</p>}
    </Dialog>
  );
}
