"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";

export default function Branches({ rows }: { rows: { id: number; name: string; address: string | null; phone: string | null; active: boolean }[] }) {
  const router = useRouter();
  const [f, setF] = useState({ name: "", address: "", phone: "" });
  const [err, setErr] = useState<string | null>(null);
  const run = async (method: string, path: string, body: unknown) => {
    const r = await call(method, path, body);
    if (!r.ok) setErr(errorText(r.data)); else { setErr(null); router.refresh(); }
    return r.ok;
  };
  return (
    <>
      <h1>Filiallar</h1>
      <form className="inline" onSubmit={async (e) => {
        e.preventDefault();
        if (await run("POST", "branches", { name: f.name, address: f.address || null, phone: f.phone || null })) setF({ name: "", address: "", phone: "" });
      }}>
        <input placeholder="Nomi" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required />
        <input placeholder="Manzil" value={f.address} onChange={(e) => setF({ ...f, address: e.target.value })} />
        <input placeholder="Telefon" value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} />
        <button className="btn">Qo&apos;shish</button>
      </form>
      {err && <p className="error">{err}</p>}
      <table>
        <thead><tr><th>Nomi</th><th>Manzil</th><th>Telefon</th><th /></tr></thead>
        <tbody>
          {rows.map((b) => (
            <tr key={b.id}>
              <td>{b.name} {!b.active && <span className="tag">yopiq</span>}</td><td>{b.address}</td><td>{b.phone}</td>
              <td>
                <button className="link" onClick={() => { const n = window.prompt("Nomi", b.name); if (n) run("PATCH", `branches/${b.id}`, { name: n }); }}>tahrirlash</button>
                <button className="link" onClick={() => run("PATCH", `branches/${b.id}`, { active: !b.active })}>{b.active ? "yopish" : "ochish"}</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </>
  );
}
