"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";

export type UserRow = { id: number; phone: string; first_name: string; last_name: string | null; role: string; active: boolean };
const ROLES: [string, string][] = [["STUDENT", "O'quvchi"], ["TEACHER", "O'qituvchi"], ["PARENT", "Ota-ona"], ["ADMIN", "Admin"], ["SUPER_ADMIN", "Super-admin"]];

export default function Users({ rows, total, actorRole, selfId }: { rows: UserRow[]; total: number; actorRole: string; selfId: number }) {
  const router = useRouter();
  const [form, setForm] = useState({ phone: "+998", first_name: "", last_name: "", role: "STUDENT", password: "" });
  const [err, setErr] = useState<string | null>(null);
  const roles = ROLES.filter(([r]) => r !== "SUPER_ADMIN" || actorRole === "SUPER_ADMIN");

  async function run(method: string, path: string, body?: unknown) {
    setErr(null);
    const r = await call(method, path, body);
    if (!r.ok) setErr(errorText(r.data));
    else router.refresh();
    return r.ok;
  }

  return (
    <>
      <h1>Foydalanuvchilar <span className="muted">({total})</span></h1>
      <form className="inline" onSubmit={async (e) => {
        e.preventDefault();
        const body = { ...form, last_name: form.last_name || null, password: form.password || undefined };
        if (await run("POST", "users", body)) setForm({ ...form, phone: "+998", first_name: "", last_name: "", password: "" });
      }}>
        <input placeholder="+998901234567" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} required />
        <input placeholder="Ism" value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required />
        <input placeholder="Familiya" value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
        <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
          {roles.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <input placeholder="Parol (ixtiyoriy)" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
        <button className="btn">Qo&apos;shish</button>
      </form>
      {err && <p className="error">{err}</p>}
      <table>
        <thead><tr><th>Telefon</th><th>Ism</th><th>Rol</th><th>Holat</th><th /></tr></thead>
        <tbody>
          {rows.map((u) => (
            <tr key={u.id}>
              <td>{u.phone}</td>
              <td>{u.first_name} {u.last_name}</td>
              <td>{ROLES.find(([r]) => r === u.role)?.[1] ?? u.role}</td>
              <td>{u.active ? "faol" : <span className="tag">bloklangan</span>}</td>
              <td>
                {u.id !== selfId && (u.role !== "SUPER_ADMIN" || actorRole === "SUPER_ADMIN") && (
                  <>
                    <button className="link" onClick={() => run("PATCH", `users/${u.id}`, { active: !u.active })}>{u.active ? "bloklash" : "faollashtirish"}</button>
                    <button className="link" onClick={() => { const p = window.prompt("Yangi parol"); if (p) run("PATCH", `users/${u.id}`, { password: p }); }}>parol</button>
                    {u.role === "PARENT" && (
                      <button className="link" onClick={async () => {
                        const phone = (window.prompt("Farzandning telefon raqami (+998…)") ?? "").trim();
                        if (!phone) return;
                        const f = await call("GET", `users?role=STUDENT&q=${encodeURIComponent(phone)}`);
                        const child = (f.data.data ?? []).find((x: UserRow) => x.phone === phone);
                        if (!child) return setErr("Bu raqamli o'quvchi topilmadi.");
                        run("POST", `users/${u.id}/children`, { child_id: child.id });
                      }}>farzand bog&apos;lash</button>
                    )}
                  </>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </>
  );
}
