"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Upload } from "lucide-react";
import { Badge, Button, Checkbox, Dialog, Field, Input, Select, Table, Td, Th, buttonClass, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDay, todayIso } from "@/lib/enrollment";
import { obj, timezone } from "@/lib/settings";
import type { GroupRow } from "@/lib/types";
import s from "./import.module.css";

type Row = {
  line: number; first_name: string; last_name: string; phone: string | null; phone_raw: string; parent_name: string; parent_phone: string | null;
  group_name: string | null; start_date: string | null; status: "ok" | "error"; action: "new" | "existing"; parent_action: "new" | "link" | "linked" | null;
  membership: "add" | "already" | null; errors: string[]; warnings: string[];
};
type Summary = { rows: number; valid: number; errors: number; new_students: number; existing_students: number; new_parents: number; links: number; memberships: number; already_members: number };
type Preview = { token: string; summary: Summary; columns: string[]; unknown_columns: string[]; rows: Row[] };
type Result = { students_created: number; students_updated: number; parents_created: number; links: number; memberships: number; skipped: number };

const ACCEPT = ".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv";

/** O'quvchilarni Excel/CSV dan import: fayl → ko'rib chiqish (bazaga yozilmaydi) → tasdiqlash. */
export default function ImportDialog({ open, onClose, onDone, defaultGroupId }: { open: boolean; onClose: () => void; onDone: () => void; defaultGroupId?: number }) {
  const st = useSiteSettings();
  const toast = useToast();
  const columns = obj<Record<string, string>>(st, "import.columns");
  const [groups, setGroups] = useState<GroupRow[]>([]);
  const [groupId, setGroupId] = useState(defaultGroupId ? String(defaultGroupId) : "");
  const [date, setDate] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [result, setResult] = useState<Result | null>(null);
  const [skip, setSkip] = useState(false);
  const [onlyErrors, setOnlyErrors] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!open) return;
    setPreview(null); setResult(null); setFile(null); setError(null); setSkip(false); setOnlyErrors(false);
    setGroupId(defaultGroupId ? String(defaultGroupId) : "");
    void call<{ data: GroupRow[] }>("GET", "groups").then((r) => r.ok && setGroups((r.data.data ?? []).filter((g) => g.active)));
  }, [open, defaultGroupId]);

  async function upload(f: File) {
    setFile(f); setError(null); setBusy(true);
    const content = await new Promise<string>((resolve, reject) => {
      const rd = new FileReader();
      rd.onload = () => resolve(String(rd.result).split(",")[1] ?? "");
      rd.onerror = () => reject(rd.error);
      rd.readAsDataURL(f);
    }).catch(() => "");
    const r = await call<Preview>("POST", "imports/students/preview", { filename: f.name, content, group_id: groupId ? Number(groupId) : null, start_date: date || null });
    setBusy(false);
    if (!r.ok) { setError(errorText(r.data)); setPreview(null); return; }
    setPreview(r.data);
  }

  async function commit() {
    if (!preview) return;
    setBusy(true);
    const r = await call<{ result: Result }>("POST", "imports/students/commit", { token: preview.token, skip_invalid: skip });
    setBusy(false);
    if (!r.ok) return setError(errorText(r.data));
    setResult(r.data.result);
    toast.success("Import yakunlandi", `${r.data.result.students_created} ta yangi o'quvchi`);
    onDone();
  }

  const rows = useMemo(() => (preview?.rows ?? []).filter((r) => !onlyErrors || r.status === "error"), [preview, onlyErrors]);
  const sm = preview?.summary;
  const canCommit = !!sm && sm.valid > 0 && (sm.errors === 0 || skip);

  return (
    <Dialog
      open={open} onClose={onClose} width={preview && !result ? 980 : 560} icon={<FileSpreadsheet />}
      title="O'quvchilarni import qilish"
      description={result ? "Natija" : preview ? `${file?.name ?? ""} — tekshirildi, hali hech narsa saqlanmadi` : "Excel (.xlsx) yoki CSV fayl"}
      footer={result ? <Button onClick={onClose}>Yopish</Button> : preview ? (
        <>
          <Button variant="ghost" onClick={() => { setPreview(null); setFile(null); }}>Boshqa fayl</Button>
          <Button icon={<Upload />} loading={busy} disabled={!canCommit} onClick={commit}>{sm ? `${sm.valid} ta qatorni import qilish` : "Import"}</Button>
        </>
      ) : <Button variant="ghost" onClick={onClose}>Bekor qilish</Button>}
    >
      {result ? (
        <div className={s.result}>
          <CheckCircle2 aria-hidden className={s.ok} />
          <ul>
            <li>Yangi o&apos;quvchilar: <b>{result.students_created}</b></li>
            {result.students_updated > 0 && <li>Ismi yangilanganlar: <b>{result.students_updated}</b></li>}
            <li>Yangi ota-onalar: <b>{result.parents_created}</b> · bog&apos;lanishlar: <b>{result.links}</b></li>
            <li>Guruhga qo&apos;shildi: <b>{result.memberships}</b></li>
            {result.skipped > 0 && <li>Tashlab ketilgan xato qatorlar: <b>{result.skipped}</b></li>}
          </ul>
        </div>
      ) : !preview ? (
        <div className={s.start}>
          <ol className={s.steps}>
            <li>
              Shablonni yuklab oling va to&apos;ldiring:{" "}
              <a className={buttonClass({ variant: "soft", size: "sm" })} href="/api/export/imports/students/template?format=xlsx"><Download aria-hidden size={16} />Excel shablon</a>{" "}
              <a className="a" href="/api/export/imports/students/template?format=csv">CSV</a>
            </li>
            <li>Ustunlar: {Object.values(columns).join(", ") || "Ism, Familiya, Telefon, Ota-ona ismi, Ota-ona telefoni, Guruh, Qo'shilgan sana"}. Majburiy: ism va telefon.</li>
            <li>Telefon istalgan ko&apos;rinishda: 90 123 45 67, +998901234567. Bir xil raqam ikki marta yaratilmaydi — faylni qayta yuklash xavfsiz.</li>
          </ol>
          <div className={s.opts}>
            <Field label="Guruh ustuni bo'sh bo'lsa" hint="Ixtiyoriy">
              <Select value={groupId} onChange={(e) => setGroupId(e.target.value)}>
                <option value="">— guruhga qo&apos;shmaslik —</option>
                {groups.map((g) => <option key={g.id} value={g.id}>{g.name}{g.course ? ` · ${g.course.title}` : ""}</option>)}
              </Select>
            </Field>
            <Field label="Qo'shilgan sana (bo'sh bo'lsa)" hint="Bo'sh — bugun">
              <Input type="date" value={date} max={todayIso(timezone(st), 60)} onChange={(e) => setDate(e.target.value)} />
            </Field>
          </div>
          <label className={s.drop} onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); const f = e.dataTransfer.files[0]; if (f) void upload(f); }}>
            <input ref={input} type="file" accept={ACCEPT} className="sr-only" onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = ""; }} />
            <Upload aria-hidden />
            <b>{busy ? "Tekshirilmoqda…" : "Faylni tanlang yoki shu yerga tashlang"}</b>
            <span className="subtle">.xlsx yoki .csv</span>
          </label>
          {error && <p className={s.error} role="alert"><AlertTriangle aria-hidden size={16} />{error}</p>}
        </div>
      ) : (
        <div className={s.preview}>
          {sm && (
            <div className={s.chips} aria-label="Xulosa">
              <Badge tone="success">To&apos;g&apos;ri: {sm.valid}</Badge>
              {sm.errors > 0 && <Badge tone="danger">Xato: {sm.errors}</Badge>}
              <Badge tone="info">Yangi o&apos;quvchi: {sm.new_students}</Badge>
              <Badge>Mavjud: {sm.existing_students}</Badge>
              <Badge>Yangi ota-ona: {sm.new_parents}</Badge>
              <Badge>Guruhga: {sm.memberships}{sm.already_members ? ` (+${sm.already_members} allaqachon)` : ""}</Badge>
            </div>
          )}
          {preview.unknown_columns.length > 0 && <p className="subtle" style={{ margin: 0 }}>E&apos;tiborsiz qoldirilgan ustunlar: {preview.unknown_columns.join(", ")}</p>}
          <div className={s.filters}>
            {sm && sm.errors > 0 && <Checkbox label="Faqat xato qatorlar" checked={onlyErrors} onChange={(e) => setOnlyErrors(e.target.checked)} />}
            {sm && sm.errors > 0 && <Checkbox label="Xato qatorlarni tashlab, qolganini import qilish" checked={skip} onChange={(e) => setSkip(e.target.checked)} />}
          </div>
          {error && <p className={s.error} role="alert"><AlertTriangle aria-hidden size={16} />{error}</p>}
          <div className={s.tableBox}>
            <Table stack caption="Import qatorlari">
              <thead><tr><Th numeric>Qator</Th><Th>O&apos;quvchi</Th><Th>Telefon</Th><Th>Ota-ona</Th><Th>Guruh</Th><Th>Natija</Th></tr></thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.line} className={r.status === "error" ? s.bad : undefined}>
                    <Td numeric label="Qator">{r.line}</Td>
                    <Td label="O'quvchi"><b>{[r.first_name, r.last_name].filter(Boolean).join(" ") || "—"}</b></Td>
                    <Td label="Telefon" className="mono">{r.phone ?? r.phone_raw}</Td>
                    <Td label="Ota-ona">{r.parent_phone ? <>{r.parent_name || "—"}<div className="subtle mono">{r.parent_phone}</div></> : "—"}</Td>
                    <Td label="Guruh">{r.group_name ? <>{r.group_name}{r.start_date && <div className="subtle">{fmtDay(r.start_date)} dan</div>}</> : "—"}</Td>
                    <Td label="Natija">
                      {r.status === "error" ? (
                        <ul className={s.msgs}>{r.errors.map((e) => <li key={e} className={s.errText}>{e}</li>)}</ul>
                      ) : (
                        <div className={s.badges}>
                          <Badge tone={r.action === "new" ? "info" : "neutral"}>{r.action === "new" ? "Yangi" : "Mavjud"}</Badge>
                          {r.parent_action === "new" && <Badge tone="info">+ ota-ona</Badge>}
                          {r.parent_action === "link" && <Badge>bog&apos;lanadi</Badge>}
                          {r.membership === "add" && <Badge tone="success">guruhga</Badge>}
                          {r.membership === "already" && <Badge>guruhda bor</Badge>}
                        </div>
                      )}
                      {r.warnings.length > 0 && <ul className={s.msgs}>{r.warnings.map((w) => <li key={w} className="subtle">{w}</li>)}</ul>}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          </div>
        </div>
      )}
    </Dialog>
  );
}
