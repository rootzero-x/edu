"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";

type Row = { student_id: number; name: string; status: string | null; arrived_at: string | null; note: string | null };
type Edit = { status: string | null; arrived_at: string; note: string };

export default function Sheet({ sessionId, rows, topic, canMark, labels }: {
  sessionId: number; rows: Row[]; topic: string; canMark: boolean; labels: Record<string, string>;
}) {
  const router = useRouter();
  const [edits, setEdits] = useState<Record<number, Edit>>(() =>
    Object.fromEntries(rows.map((r) => [r.student_id, { status: r.status, arrived_at: r.arrived_at ? r.arrived_at.slice(11, 16) : "", note: r.note ?? "" }])));
  const [t, setT] = useState(topic);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const set = (id: number, patch: Partial<Edit>) => setEdits((e) => ({ ...e, [id]: { ...e[id], ...patch } }));

  async function save() {
    setMsg(null);
    const items = Object.entries(edits)
      .filter(([, e]) => e.status || e.arrived_at)
      .map(([id, e]) => ({
        student_id: Number(id),
        // Vaqt kiritilgan va holat tanlanmagan bo'lsa, server sozlamalar bo'yicha o'zi aniqlaydi.
        status: e.arrived_at && !e.status ? null : e.status,
        arrived_at: e.arrived_at || null,
        note: e.note || null,
      }));
    if (!items.length) return setMsg({ ok: false, text: "Hech kim belgilanmagan." });
    const r = await call("PUT", `sessions/${sessionId}/attendance`, { items });
    if (!r.ok) return setMsg({ ok: false, text: errorText(r.data) });
    if (t !== topic) await call("PATCH", `sessions/${sessionId}`, { topic: t || null });
    setMsg({ ok: true, text: `Saqlandi.${r.data.notified ? ` Ota-onalarga ${r.data.notified} ta xabar yuborildi.` : ""}` });
    router.refresh();
  }

  return (
    <div className="card">
      {canMark && <label className="form">Dars mavzusi<input value={t} onChange={(e) => setT(e.target.value)} /></label>}
      {!canMark && topic && <p className="muted">Mavzu: {topic}</p>}
      <table>
        <thead><tr><th>O&apos;quvchi</th><th>Holat</th>{canMark && <><th>Kelgan vaqti</th><th>Izoh</th></>}</tr></thead>
        <tbody>
          {rows.map((r) => {
            const e = edits[r.student_id];
            return (
              <tr key={r.student_id}>
                <td>{r.name}</td>
                <td>
                  {canMark ? (
                    <div className="seg">
                      {Object.entries(labels).map(([k, l]) => (
                        <button key={k} type="button" className={e.status === k ? `on s-${k}` : ""} onClick={() => set(r.student_id, { status: e.status === k ? null : k })}>{l}</button>
                      ))}
                    </div>
                  ) : (r.status ? labels[r.status] ?? r.status : "—")}
                </td>
                {canMark && (
                  <>
                    <td><input type="time" value={e.arrived_at} onChange={(ev) => set(r.student_id, { arrived_at: ev.target.value })} /></td>
                    <td><input value={e.note} onChange={(ev) => set(r.student_id, { note: ev.target.value })} /></td>
                  </>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>
      {rows.length === 0 && <p className="muted">Guruhda o&apos;quvchi yo&apos;q.</p>}
      {canMark && rows.length > 0 && (
        <div className="toolbar">
          <button className="btn" onClick={save}>Saqlash</button>
          <button className="btn ghost" onClick={() => rows.forEach((r) => !edits[r.student_id].status && set(r.student_id, { status: "PRESENT" }))}>
            Qolganlarni «{labels.PRESENT ?? "PRESENT"}»
          </button>
        </div>
      )}
      {msg && <p className={msg.ok ? "ok" : "error"}>{msg.text}</p>}
    </div>
  );
}
