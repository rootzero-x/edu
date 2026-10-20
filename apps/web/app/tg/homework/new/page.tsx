"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { errorText, tg, tgUpload } from "../../_lib/api";
import { useTg } from "../../_lib/TgProvider";
import type { Group } from "../../_lib/data";
import { haptic } from "../../_lib/webapp";
import { Async, Empty, MainButton, Page, Row, Section, useApi } from "../../_ui/ui";
import { FileAttach } from "../../_ui/files";
import { teachingGroups } from "../../_ui/sessions";
import { fromLocalInput, type Assignment } from "@/lib/homework";
import { fmtSize } from "@/lib/upload";

/** O'qituvchi: telefondan tezkor vazifa berish (guruh, sarlavha, matn, muddat, fayl). */
export default function TgNewAssignment() {
  const { me, t, tz, can } = useTg();
  const router = useRouter();
  const groups = useApi<{ data: Group[] }>("groups");
  const [f, setF] = useState({ group_id: "", title: "", body: "", due: "" });
  const [files, setFiles] = useState<File[]>([]);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  if (!can("attendance.mark")) return <Page><Empty text={t("no_access")} icon="🔒" /></Page>;

  async function create(groupId: string) {
    setBusy(true);
    setErr(null);
    const r = await tg<{ assignment: Assignment }>("POST", `groups/${groupId}/assignments`, {
      title: f.title.trim(), body: f.body.trim() || null, due_at: fromLocalInput(f.due, tz),
    });
    if (!r.ok) { setBusy(false); haptic.error(); return setErr(errorText(r.data, t("error"))); }
    for (const file of files) {
      const u = await tgUpload(`assignments/${r.data.assignment.id}/files`, file);
      if (!u.ok) setErr(`${file.name}: ${errorText(u.data, t("error"))}`);
    }
    setBusy(false);
    haptic.success();
    router.replace(`/tg/homework/${r.data.assignment.id}`);
  }

  return (
    <Page title={t("hw_new")}>
      <Async q={groups}>
        {(d) => {
          const mine = teachingGroups(d.data, me.id, can("group.manage"));
          const gid = f.group_id || (mine.length === 1 ? String(mine[0].id) : "");
          if (mine.length === 0) return <Empty text={t("courses_empty")} icon="👥" />;
          return (
            <>
              <Section title={t("hw_group")}>
                <div className="tg-pad">
                  <select className="tg-input" value={gid} onChange={(e) => setF({ ...f, group_id: e.target.value })}>
                    <option value="">—</option>
                    {mine.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
                  </select>
                </div>
              </Section>
              <Section title={t("hw_title")}>
                <div className="tg-pad" style={{ display: "grid", gap: 10 }}>
                  <input className="tg-input" value={f.title} maxLength={200} onChange={(e) => setF({ ...f, title: e.target.value })} placeholder={t("hw_title")} />
                  <textarea className="tg-input tg-textarea" value={f.body} maxLength={20000} onChange={(e) => setF({ ...f, body: e.target.value })} placeholder={t("hw_body")} />
                  <label className="tg-hint" style={{ display: "grid", gap: 6 }}>{t("hw_due_input")}
                    <input className="tg-input" type="datetime-local" value={f.due} onChange={(e) => setF({ ...f, due: e.target.value })} />
                  </label>
                  <FileAttach staff onPick={(l) => setFiles((x) => [...x, ...l])} disabled={busy} />
                </div>
                {files.map((x, i) => <Row key={i} icon="📎" title={x.name} subtitle={fmtSize(x.size)} right={<button onClick={() => setFiles((y) => y.filter((_, j) => j !== i))} aria-label="✕">✕</button>} />)}
              </Section>
              {err && <p className="tg-hint" style={{ textAlign: "center", color: "var(--c-danger)" }}>{err}</p>}
              <MainButton text={t("hw_create")} onClick={() => create(gid)} progress={busy} disabled={!gid || !f.title.trim()} />
            </>
          );
        }}
      </Async>
    </Page>
  );
}
