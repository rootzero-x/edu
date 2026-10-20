"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";
import { personName } from "@/lib/format";

export default function GroupAdmin({ groupId, members, allStudents }: { groupId: number; members: any[]; allStudents: any[] }) {
  const router = useRouter();
  const [pick, setPick] = useState("");
  const [msg, setMsg] = useState<string | null>(null);
  const memberIds = new Set(members.map((m) => m.id));
  const candidates = allStudents.filter((s) => !memberIds.has(s.id));

  async function run(method: string, path: string, body?: unknown, ok?: (d: any) => string) {
    setMsg(null);
    const r = await call(method, path, body);
    setMsg(r.ok ? (ok ? ok(r.data) : null) : errorText(r.data));
    if (r.ok) router.refresh();
  }

  return (
    <>
      <ul className="plain">
        {members.map((m) => (
          <li key={m.id}>
            {personName(m)} <span className="muted">{m.phone}</span>
            <button className="link danger" onClick={() => window.confirm("Guruhdan chiqarilsinmi?") && run("DELETE", `groups/${groupId}/members/${m.id}`)}>chiqarish</button>
          </li>
        ))}
      </ul>
      <div className="inline">
        <select value={pick} onChange={(e) => setPick(e.target.value)}>
          <option value="">O&apos;quvchi tanlang…</option>
          {candidates.map((s) => <option key={s.id} value={s.id}>{personName(s)} · {s.phone}</option>)}
        </select>
        <button className="btn" disabled={!pick} onClick={() => run("POST", `groups/${groupId}/members`, { student_id: Number(pick) }).then(() => setPick(""))}>Qo&apos;shish</button>
      </div>
      <button className="link" onClick={() => run("POST", `groups/${groupId}/sessions/generate`, {}, (d) => `${d.created} ta yangi dars qo'shildi (${d.from} — ${d.to}).`)}>
        Jadvaldan darslarni yangilash
      </button>
      {msg && <p className="muted">{msg}</p>}
    </>
  );
}
