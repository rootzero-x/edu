"use client";

import { useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";

export type Item = {
  key: string; group: string; type: "int" | "bool" | "string" | "text" | "list" | "object";
  label: string; public: boolean; min: number | null; max: number | null; default: unknown; value: unknown;
};

const GROUPS: Record<string, string> = {
  site: "Sayt", attendance: "Davomat", quiz: "Testlar", certificate: "Sertifikat", billing: "Moliya",
  telegram: "Telegram bot", security: "Xavfsizlik", ui: "Interfeys", roles: "Rollar huquqlari",
};
const ROLE_NAMES: Record<string, string> = { ADMIN: "Admin", TEACHER: "O'qituvchi", STUDENT: "O'quvchi", PARENT: "Ota-ona" };

/** Barcha sozlamalar API'dagi ta'riflardan avtomatik quriladi — yangi sozlama qo'shilsa, forma o'zi paydo bo'ladi. */
export default function SettingsEditor({ items, permissions }: { items: Item[]; permissions: string[] }) {
  const router = useRouter();
  const [draft, setDraft] = useState<Record<string, unknown>>({});
  const [raw, setRaw] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const groups = useMemo(() => Object.entries(items.reduce<Record<string, Item[]>>((a, i) => ((a[i.group] ??= []).push(i), a), {})), [items]);
  const value = (i: Item) => (i.key in draft ? draft[i.key] : i.value);
  const set = (k: string, v: unknown) => setDraft((d) => ({ ...d, [k]: v }));
  const dirty = Object.keys(draft).length > 0;

  async function save() {
    setMsg(null);
    const r = await call("PATCH", "settings", { values: draft });
    if (!r.ok) return setMsg({ ok: false, text: errorText(r.data) });
    setDraft({}); setRaw({});
    setMsg({ ok: true, text: "Saqlandi." });
    router.refresh();
  }

  async function reset(key: string) {
    if (!window.confirm("Boshlang'ich qiymatga qaytarilsinmi?")) return;
    const r = await call("DELETE", `settings/${encodeURIComponent(key)}`);
    if (r.ok) router.refresh();
  }

  function field(i: Item) {
    const v = value(i);
    switch (i.type) {
      case "bool":
        return <input type="checkbox" checked={Boolean(v)} onChange={(e) => set(i.key, e.target.checked)} />;
      case "int":
        return <input type="number" min={i.min ?? undefined} max={i.max ?? undefined} value={String(v)} onChange={(e) => set(i.key, Number.parseInt(e.target.value || "0", 10))} />;
      case "string":
        return <input value={String(v ?? "")} onChange={(e) => set(i.key, e.target.value)} />;
      case "text":
        return <textarea rows={3} value={String(v ?? "")} onChange={(e) => set(i.key, e.target.value)} />;
      case "object":
        if (i.key === "roles.permissions") return rolesMatrix(i, v as Record<string, string[]>);
      // falls through
      case "list":
        return (
          <textarea rows={6} className="mono" value={raw[i.key] ?? JSON.stringify(v, null, 2)}
            onChange={(e) => {
              setRaw((r) => ({ ...r, [i.key]: e.target.value }));
              try { set(i.key, JSON.parse(e.target.value)); } catch { /* JSON hali to'liq emas */ }
            }} />
        );
    }
  }

  function rolesMatrix(i: Item, v: Record<string, string[]>) {
    return (
      <table className="matrix">
        <thead><tr><th />{Object.keys(ROLE_NAMES).map((r) => <th key={r}>{ROLE_NAMES[r]}</th>)}</tr></thead>
        <tbody>
          {permissions.map((p) => (
            <tr key={p}>
              <td className="mono">{p}</td>
              {Object.keys(ROLE_NAMES).map((r) => (
                <td key={r}>
                  <input type="checkbox" checked={(v[r] ?? []).includes(p)} onChange={(e) => {
                    const cur = new Set(v[r] ?? []);
                    e.target.checked ? cur.add(p) : cur.delete(p);
                    set(i.key, { ...v, [r]: [...cur] });
                  }} />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    );
  }

  return (
    <>
      <div className="row sticky">
        <h1>Sozlamalar</h1>
        <span>
          {msg && <span className={msg.ok ? "ok" : "error"}>{msg.text} </span>}
          <button className="btn" disabled={!dirty} onClick={save}>Saqlash</button>
        </span>
      </div>
      {groups.map(([g, list]) => (
        <div className="card" key={g}>
          <h2>{GROUPS[g] ?? g}</h2>
          {list.map((i) => (
            <div className="setting" key={i.key}>
              <label>
                {i.label} {i.public && <span className="tag">ochiq</span>}
                <small className="mono">{i.key}</small>
              </label>
              <div>{field(i)}</div>
              <button className="link" title="Boshlang'ich qiymat" onClick={() => reset(i.key)}>↺</button>
            </div>
          ))}
        </div>
      ))}
    </>
  );
}
