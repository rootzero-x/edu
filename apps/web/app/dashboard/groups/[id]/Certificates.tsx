"use client";

import { useState } from "react";
import { call, errorText } from "@/lib/client";

const REASONS: Record<string, string> = { LOW_ATTENDANCE: "davomat past", QUIZ_NOT_PASSED: "yakuniy test o'tilmagan", HAS_DEBT: "qarzdorlik" };

export default function Certificates({ groupId }: { groupId: number }) {
  const [rows, setRows] = useState<any[] | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const load = async () => {
    const r = await call("GET", `groups/${groupId}/certificates`);
    r.ok ? setRows(r.data.data) : setMsg(errorText(r.data));
  };
  return (
    <div className="card">
      <div className="row">
        <h2>Sertifikatlar</h2>
        {!rows ? <button className="link" onClick={load}>Shartlarni tekshirish</button> : (
          <button className="btn" disabled={!rows.some((r) => !r.certificate && r.blockers.length === 0)} onClick={async () => {
            if (!window.confirm("Shartlarga mos o'quvchilarga sertifikat berilsinmi?")) return;
            const r = await call("POST", `groups/${groupId}/certificates`, {});
            if (!r.ok) return setMsg(errorText(r.data));
            setRows(r.data.data);
            setMsg(`${r.data.issued.length} ta sertifikat berildi.`);
          }}>Sertifikat berish</button>
        )}
      </div>
      {rows && (
        <table>
          <thead><tr><th>O&apos;quvchi</th><th>Davomat</th><th>Yakuniy test</th><th>Qarz</th><th>Holat</th></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.student_id}>
                <td>{r.name}</td><td>{r.attendance_rate}%</td><td>{r.final_quiz_passed ? "✅" : "—"}</td><td>{r.debt > 0 ? r.debt.toLocaleString("uz-UZ") : "—"}</td>
                <td>{r.certificate
                  ? <a className="a" href={`/c/${r.certificate}`} target="_blank" rel="noreferrer">{r.certificate}</a>
                  : r.blockers.length ? <span className="muted">{r.blockers.map((b: string) => REASONS[b] ?? b).join(", ")}</span>
                  : <span className="tag ok">berish mumkin</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {msg && <p className="muted">{msg}</p>}
    </div>
  );
}
