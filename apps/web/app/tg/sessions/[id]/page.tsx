"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { errorText, tg } from "../../_lib/api";
import { useTg } from "../../_lib/TgProvider";
import { date, time } from "../../_lib/format";
import { haptic } from "../../_lib/webapp";
import { Async, Badge, Empty, MainButton, Page, Section, useApi } from "../../_ui/ui";

type SheetRow = { student_id: number; name: string; status: string | null; arrived_at: string | null; note: string | null };
type Sheet = { session: { id: number; starts_at: string; topic: string | null; cancelled: boolean }; group: { id: number; name: string }; rows: SheetRow[] };

const STATUSES = ["PRESENT", "LATE", "ABSENT", "EXCUSED"];

/** Dars davomati: o'qituvchi belgilaydi (PUT /sessions/{id}/attendance), boshqalar ko'radi. */
export default function TgSession() {
  const { id } = useParams<{ id: string }>();
  const q = useApi<Sheet>(`sessions/${id}/attendance`);
  return <Async q={q}>{(sheet) => <SheetView sheet={sheet} reload={q.reload} />}</Async>;
}

function SheetView({ sheet, reload }: { sheet: Sheet; reload: () => void }) {
  const { t, tz, settings, can } = useTg();
  const labels = (settings["attendance.status_labels"] ?? {}) as Record<string, string>;
  const emoji = (settings["telegram.attendance_emoji"] ?? {}) as Record<string, string>;
  const canMark = can("attendance.mark") && !sheet.session.cancelled;
  const initial = () => Object.fromEntries(sheet.rows.map((r) => [r.student_id, r.status]));
  const [status, setStatus] = useState<Record<number, string | null>>(initial);
  const [topic, setTopic] = useState(sheet.session.topic ?? "");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  useEffect(() => setStatus(initial()), [sheet]);

  const changed = sheet.rows.filter((r) => status[r.student_id] && status[r.student_id] !== r.status);
  const topicChanged = topic !== (sheet.session.topic ?? "");
  const marked = Object.values(status).filter(Boolean).length;

  const pick = (sid: number, s: string) => {
    haptic.select();
    setMsg(null);
    setStatus((x) => ({ ...x, [sid]: x[sid] === s ? sheet.rows.find((r) => r.student_id === sid)?.status ?? null : s }));
  };

  async function save() {
    if (!changed.length && !topicChanged) return;
    setBusy(true);
    setMsg(null);
    let notified = 0;
    if (changed.length) {
      const r = await tg<{ notified: number }>("PUT", `sessions/${sheet.session.id}/attendance`, {
        items: changed.map((x) => ({ student_id: x.student_id, status: status[x.student_id] })),
      });
      if (!r.ok) {
        setBusy(false);
        haptic.error();
        return setMsg({ ok: false, text: errorText(r.data, t("error")) });
      }
      notified = r.data.notified ?? 0;
    }
    if (topicChanged) {
      const r = await tg("PATCH", `sessions/${sheet.session.id}`, { topic: topic || null });
      if (!r.ok) {
        setBusy(false);
        haptic.error();
        return setMsg({ ok: false, text: errorText(r.data, t("error")) });
      }
    }
    setBusy(false);
    haptic.success();
    setMsg({ ok: true, text: notified ? t("sheet_saved_notified", { n: notified }) : t("sheet_saved") });
    reload();
  }

  return (
    <Page title={sheet.group.name} subtitle={`${date(sheet.session.starts_at, tz)}, ${time(sheet.session.starts_at, tz)}`}>
      {sheet.session.cancelled && <Badge tone="bad">{t("cancelled")}</Badge>}
      {canMark && (
        <Section title={t("sheet_topic")}>
          <div className="tg-pad"><input className="tg-input" value={topic} onChange={(e) => setTopic(e.target.value)} /></div>
        </Section>
      )}
      <Section
        title={t("sheet_marked", { marked, total: sheet.rows.length })}
        right={canMark && sheet.rows.some((r) => !status[r.student_id])
          ? <button onClick={() => { haptic.tap(); setStatus((x) => Object.fromEntries(sheet.rows.map((r) => [r.student_id, x[r.student_id] ?? "PRESENT"]))); }}>{t("sheet_all_present")}</button>
          : undefined}>
        {sheet.rows.length === 0 && <Empty text={t("sheet_empty")} icon="👥" />}
        {sheet.rows.map((r) => {
          const cur = status[r.student_id];
          return (
            <div key={r.student_id} className="tg-student">
              <div className="name">
                <span>{r.name}</span>
                {!canMark && <span>{cur ? `${emoji[cur] ?? ""} ${labels[cur] ?? cur}` : "—"}</span>}
              </div>
              {canMark && (
                <div className="tg-seg">
                  {STATUSES.map((s) => (
                    <button key={s} className={cur === s ? `on ${s}` : ""} onClick={() => pick(r.student_id, s)}>{labels[s] ?? s}</button>
                  ))}
                </div>
              )}
            </div>
          );
        })}
      </Section>
      {msg && <p className="tg-hint" style={{ textAlign: "center", marginTop: 14, color: msg.ok ? "var(--c-ok)" : "var(--c-danger)" }}>{msg.text}</p>}
      {canMark && (
        <MainButton text={t("sheet_save")} onClick={save} progress={busy} disabled={!changed.length && !topicChanged} hidden={sheet.rows.length === 0} />
      )}
    </Page>
  );
}
