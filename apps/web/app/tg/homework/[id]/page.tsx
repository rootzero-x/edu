"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { errorText, tg, tgUpload } from "../../_lib/api";
import { useTg } from "../../_lib/TgProvider";
import { date, time } from "../../_lib/format";
import { haptic, openLink } from "../../_lib/webapp";
import { Async, Badge, Empty, MainButton, Page, Row, Section, useApi } from "../../_ui/ui";
import { FileAttach, FileRows } from "../../_ui/files";
import type { Assignment, Mine, SubmissionRow } from "@/lib/homework";
import { fmtSize } from "@/lib/upload";

/** Vazifa: o'quvchi — topshiradi (matn, havola, fayl); o'qituvchi — javoblarni ko'radi va baholaydi. */
export default function TgAssignment() {
  const { id } = useParams<{ id: string }>();
  const q = useApi<{ assignment: Assignment }>(`assignments/${id}`);
  return <Async q={q}>{(d) => <View a={d.assignment} reload={q.reload} />}</Async>;
}

function View({ a, reload }: { a: Assignment; reload: () => void }) {
  const { t, tz, settings } = useTg();
  const labels = (settings["homework.status_labels"] ?? {}) as Record<string, string>;
  const [err, setErr] = useState<string | null>(null);
  return (
    <Page title={a.title} subtitle={`${a.group_name ?? ""} · ${a.due_at ? t("hw_due", { date: `${date(a.due_at, tz)} ${time(a.due_at, tz)}` }) : t("hw_no_due")}`}>
      {a.my && <p style={{ margin: "0 4px 10px" }}><Badge tone={a.my.status === "GRADED" ? "ok" : a.my.status === "MISSED" ? "bad" : a.my.status === "SUBMITTED" ? "accent" : "warn"}>{labels[a.my.status] ?? a.my.status}</Badge></p>}
      {a.body && <Section><div className="tg-prose">{a.body}</div></Section>}
      {a.files.length > 0 && <Section title={t("hw_files")}><FileRows files={a.files} onError={setErr} /></Section>}
      {err !== null && <p className="tg-hint" style={{ color: "var(--c-danger)", textAlign: "center" }}>{err || t("error")}</p>}
      {a.can_manage ? <Grade a={a} reload={reload} /> : a.my && <Submit a={a} mine={a.my} reload={reload} />}
    </Page>
  );
}

function Submit({ a, mine, reload }: { a: Assignment; mine: Mine; reload: () => void }) {
  const { t, tz } = useTg();
  const [body, setBody] = useState(mine.body ?? "");
  const [link, setLink] = useState(mine.link_url ?? "");
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  async function send() {
    setBusy(true);
    setMsg(null);
    for (const f of files) {
      const u = await tgUpload(`assignments/${a.id}/submission/files`, f);
      if (!u.ok) { setBusy(false); haptic.error(); return setMsg({ ok: false, text: `${f.name}: ${errorText(u.data, t("error"))}` }); }
    }
    const r = await tg<{ my: Mine }>("PUT", `assignments/${a.id}/submission`, { body: body.trim() || null, link_url: link.trim() || null });
    setBusy(false);
    if (!r.ok) { haptic.error(); return setMsg({ ok: false, text: errorText(r.data, t("error")) }); }
    haptic.success();
    setFiles([]);
    setMsg({ ok: true, text: t("hw_submitted") });
    reload();
  }

  return (
    <>
      {mine.score != null && (
        <Section title={t("hw_result", { score: mine.score, max: a.max_score })}>
          {mine.feedback ? <div className="tg-prose">{mine.feedback}</div> : <div className="tg-pad tg-hint">{t("hw_no_feedback")}</div>}
        </Section>
      )}
      <Section title={t("hw_answer")}>
        <div className="tg-pad" style={{ display: "grid", gap: 10 }}>
          {mine.submitted_at && <p className="tg-hint" style={{ margin: 0 }}>{t("hw_submitted_at", { date: `${date(mine.submitted_at, tz)} ${time(mine.submitted_at, tz)}` })}</p>}
          {mine.can_submit ? (
            <>
              <textarea className="tg-input tg-textarea" value={body} maxLength={20000} placeholder={t("hw_answer")} onChange={(e) => setBody(e.target.value)} />
              <input className="tg-input" type="url" inputMode="url" value={link} placeholder={t("hw_link")} onChange={(e) => setLink(e.target.value)} />
              <FileAttach onPick={(l) => setFiles((x) => [...x, ...l])} disabled={busy} />
            </>
          ) : (
            <>
              {mine.body && <p className="tg-answer">{mine.body}</p>}
              {mine.link_url && <button className="tg-btn ghost" onClick={() => openLink(mine.link_url!)}>🔗 {t("hw_link_open")}</button>}
              {!mine.submitted_at && <p className="tg-hint" style={{ margin: 0 }}>{t("hw_locked")}</p>}
            </>
          )}
        </div>
        <FileRows files={mine.files} />
        {files.map((f, i) => <Row key={i} icon="⏳" title={f.name} subtitle={fmtSize(f.size)} right={<button onClick={() => setFiles((x) => x.filter((_, j) => j !== i))} aria-label="✕">✕</button>} />)}
      </Section>
      {msg && <p className="tg-hint" style={{ textAlign: "center", color: msg.ok ? "var(--c-ok)" : "var(--c-danger)" }}>{msg.text}</p>}
      {mine.can_submit && (
        <MainButton text={mine.submitted_at ? t("hw_resubmit") : t("hw_submit")} onClick={send} progress={busy}
          disabled={!body.trim() && !link.trim() && files.length === 0 && mine.files.length === 0} />
      )}
    </>
  );
}

function Grade({ a, reload }: { a: Assignment; reload: () => void }) {
  const { t, settings } = useTg();
  const labels = (settings["homework.status_labels"] ?? {}) as Record<string, string>;
  const rows = a.submissions ?? [];
  const [scores, setScores] = useState<Record<number, string>>({});
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  useEffect(() => setScores({}), [a]);
  const val = (r: SubmissionRow) => scores[r.student_id] ?? (r.score == null ? "" : String(r.score));
  const changed = rows.filter((r) => scores[r.student_id] !== undefined && scores[r.student_id] !== (r.score == null ? "" : String(r.score)));

  async function save() {
    const bad = changed.find((r) => { const v = val(r); return v !== "" && (!/^\d+$/.test(v) || +v > a.max_score); });
    if (bad) return setMsg({ ok: false, text: `${bad.name}: 0–${a.max_score}` });
    setBusy(true);
    const r = await tg("PUT", `assignments/${a.id}/grades`, { items: changed.map((x) => ({ student_id: x.student_id, score: val(x) === "" ? null : Number(val(x)) })) });
    setBusy(false);
    if (!r.ok) { haptic.error(); return setMsg({ ok: false, text: errorText(r.data, t("error")) }); }
    haptic.success();
    setMsg({ ok: true, text: t("hw_grades_saved") });
    reload();
  }

  return (
    <>
      {a.stats && <p className="tg-hint" style={{ margin: "0 4px 10px" }}>{t("hw_stats", { submitted: a.stats.submitted, total: a.stats.total })}</p>}
      <Section title={t("hw_submissions")}>
        {rows.length === 0 && <Empty text={t("sheet_empty")} icon="👥" />}
        {rows.map((r) => (
          <div key={r.student_id} className="tg-student">
            <div className="name">
              <span>{r.name}</span>
              <span style={{ display: "flex", alignItems: "center", gap: 8 }}>
                <Badge tone={r.status === "GRADED" ? "ok" : r.status === "MISSED" ? "bad" : r.status === "PENDING" ? "muted" : "accent"}>{labels[r.status] ?? r.status}</Badge>
                <input className="tg-input tg-score" type="number" inputMode="numeric" min={0} max={a.max_score} aria-label={`${r.name}: ${t("hw_score")}`}
                  placeholder={`/${a.max_score}`} value={val(r)} onChange={(e) => setScores((x) => ({ ...x, [r.student_id]: e.target.value }))} />
              </span>
            </div>
            {r.body && <p className="tg-answer">{r.body}</p>}
            {r.link_url && <button className="tg-btn ghost" style={{ marginTop: 6 }} onClick={() => openLink(r.link_url!)}>🔗 {t("hw_link_open")}</button>}
            {r.files.length > 0 && <div style={{ marginTop: 6 }}><FileRows files={r.files} /></div>}
          </div>
        ))}
      </Section>
      {msg && <p className="tg-hint" style={{ textAlign: "center", color: msg.ok ? "var(--c-ok)" : "var(--c-danger)" }}>{msg.text}</p>}
      <MainButton text={t("hw_save_grades")} onClick={save} progress={busy} disabled={changed.length === 0} hidden={rows.length === 0} />
    </>
  );
}
