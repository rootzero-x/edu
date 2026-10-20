"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Braces, List, Plus, RotateCcw, Search, Trash2 } from "lucide-react";
import { Badge, Button, Card, EmptyState, Input, PageHeader, Switch, Table, Td, Th, Textarea, cx, useConfirm, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";
import s from "./settings.module.css";

export type Item = {
  key: string; group: string; type: "int" | "bool" | "string" | "text" | "list" | "object";
  label: string; public: boolean; min: number | null; max: number | null; default: unknown; value: unknown;
};

const ROLE_NAMES: Record<string, string> = { ADMIN: "Admin", TEACHER: "O'qituvchi", STUDENT: "O'quvchi", PARENT: "Ota-ona" };
const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

/** Ro'yxat elementlarining maydonlari: yorliqdagi "[{a,b}]" dan yoki mavjud qiymatlardan. */
function listShape(i: Item): string[] | "string" | null {
  const m = /\[\{([\w,\s]+)\}\]/.exec(i.label);
  if (m) return m[1].split(",").map((x) => x.trim()).filter(Boolean);
  const sample = [...(Array.isArray(i.value) ? i.value : []), ...(Array.isArray(i.default) ? i.default : [])];
  if (sample.length && sample.every((x) => typeof x === "string")) return "string";
  if (sample.length && sample.every((x) => x && typeof x === "object" && !Array.isArray(x))) {
    const keys = new Set<string>();
    sample.forEach((x) => Object.keys(x as object).forEach((k) => keys.add(k)));
    if ([...keys].every((k) => sample.every((x) => ["string", "number", "undefined"].includes(typeof (x as Record<string, unknown>)[k])))) return [...keys];
  }
  return null;
}

/**
 * Barcha sozlamalar API'dagi ta'riflardan avtomatik quriladi — config/tisu.php ga yangi sozlama
 * qo'shilsa, forma o'zi paydo bo'ladi (tur bo'yicha mos boshqaruv elementi bilan).
 */
export default function SettingsEditor({ items, permissions, permissionLabels = {}, groupLabels = {}, warnings = [], showKeys = false }: {
  items: Item[]; permissions: string[]; permissionLabels?: Record<string, string>; groupLabels?: Record<string, string>;
  /** Backend ogohlantirishlari (masalan, o'qituvchida course.manage qolgan). */
  warnings?: string[];
  /** Texnik kalitlar (sozlama kaliti, huquq kodi) faqat SUPER_ADMIN ga ko'rinadi; boshqalarga — tooltip. */
  showKeys?: boolean;
}) {
  const GROUPS = groupLabels;
  const router = useRouter();
  const toast = useToast();
  const confirm = useConfirm();
  const [draft, setDraft] = useState<Record<string, unknown>>({});
  const [raw, setRaw] = useState<Record<string, string>>({});
  const [rawMode, setRawMode] = useState<Record<string, boolean>>({});
  const [invalid, setInvalid] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState(false);
  const [q, setQ] = useState("");
  // Guruhlar tartibi API'dagi groups ro'yxatidan; ro'yxatda yo'qlari oxirida.
  const groups = useMemo(() => {
    const by = items.reduce<Record<string, Item[]>>((a, i) => ((a[i.group] ??= []).push(i), a), {});
    const order = Object.keys(groupLabels);
    return Object.entries(by).sort(([a], [b]) => (order.indexOf(a) + 1 || 999) - (order.indexOf(b) + 1 || 999));
  }, [items, groupLabels]);
  const [active, setActive] = useState(groups[0]?.[0] ?? "site");

  const value = (i: Item) => (i.key in draft ? draft[i.key] : i.value);
  const set = (k: string, v: unknown) => setDraft((d) => {
    const item = items.find((x) => x.key === k);
    const next = { ...d };
    if (item && same(item.value, v)) delete next[k]; else next[k] = v;
    return next;
  });
  const dirty = Object.keys(draft).length;
  const hasInvalid = Object.values(invalid).some(Boolean);

  useEffect(() => {
    const onUnload = (e: BeforeUnloadEvent) => { if (dirty) e.preventDefault(); };
    window.addEventListener("beforeunload", onUnload);
    return () => window.removeEventListener("beforeunload", onUnload);
  }, [dirty]);

  async function save() {
    setBusy(true);
    const r = await call("PATCH", "settings", { values: draft });
    setBusy(false);
    if (!r.ok) return toast.error("Saqlanmadi", errorText(r.data));
    toast.success("Sozlamalar saqlandi", `${dirty} ta o'zgarish`);
    setDraft({}); setRaw({}); setInvalid({});
    router.refresh();
  }

  function discard() {
    setDraft({}); setRaw({}); setInvalid({});
  }

  async function reset(i: Item) {
    if (!(await confirm({ title: "Boshlang'ich qiymatga qaytarilsinmi?", description: i.label, confirmText: "Qaytarish" }))) return;
    const r = await call("DELETE", `settings/${encodeURIComponent(i.key)}`);
    if (!r.ok) return toast.error("Bajarilmadi", errorText(r.data));
    setDraft((d) => { const n = { ...d }; delete n[i.key]; return n; });
    toast.success("Qaytarildi", i.label);
    router.refresh();
  }

  function jsonField(i: Item, v: unknown) {
    return (
      <div>
        <Textarea rows={6} className="mono" aria-invalid={invalid[i.key] || undefined} aria-label={i.label} value={raw[i.key] ?? JSON.stringify(v, null, 2)}
          onChange={(e) => {
            setRaw((r) => ({ ...r, [i.key]: e.target.value }));
            try { set(i.key, JSON.parse(e.target.value)); setInvalid((x) => ({ ...x, [i.key]: false })); } catch { setInvalid((x) => ({ ...x, [i.key]: true })); }
          }} />
        {invalid[i.key] && <div className={s.jsonErr} role="alert">JSON noto&apos;g&apos;ri — saqlanmaydi.</div>}
      </div>
    );
  }

  function listField(i: Item, v: unknown) {
    const shape = listShape(i);
    if (!shape || rawMode[i.key]) return jsonField(i, v);
    const arr = Array.isArray(v) ? v : [];
    const update = (next: unknown[]) => set(i.key, next);
    const blank = shape === "string" ? "" : Object.fromEntries(shape.map((k) => [k, ""]));
    return (
      <div className={s.listRows}>
        {arr.map((row, idx) => (
          <div key={idx} className={s.listRow} style={{ "--cols": shape === "string" ? 1 : shape.length } as React.CSSProperties}>
            {shape === "string" ? (
              <Input size="sm" aria-label={`${i.label} — ${idx + 1}`} value={String(row ?? "")} onChange={(e) => update(arr.map((x, j) => (j === idx ? e.target.value : x)))} />
            ) : shape.map((k) => {
              const cell = (row as Record<string, unknown>)?.[k];
              const long = k === "text" || k === "description";
              const onChange = (val: string) => update(arr.map((x, j) => (j === idx ? { ...(x as object), [k]: val } : x)));
              return long
                ? <Textarea key={k} rows={2} placeholder={k} aria-label={`${k} — ${idx + 1}`} value={String(cell ?? "")} onChange={(e) => onChange(e.target.value)} style={{ minHeight: 40 }} />
                : <Input key={k} size="sm" placeholder={k} aria-label={`${k} — ${idx + 1}`} value={String(cell ?? "")} onChange={(e) => onChange(e.target.value)} />;
            })}
            <Button size="sm" variant="dangerGhost" iconOnly aria-label="O'chirish" icon={<Trash2 />} onClick={() => update(arr.filter((_, j) => j !== idx))} />
          </div>
        ))}
        <div className={s.listActions}>
          <Button size="sm" variant="soft" icon={<Plus />} onClick={() => update([...arr, blank])}>Qo&apos;shish</Button>
          <Button size="sm" variant="ghost" icon={<Braces />} onClick={() => setRawMode((m) => ({ ...m, [i.key]: true }))}>JSON</Button>
        </div>
      </div>
    );
  }

  function objectField(i: Item, v: Record<string, unknown>) {
    if (i.key === "roles.permissions") return rolesMatrix(i, v as Record<string, string[]>);
    const entries = Object.entries(v ?? {});
    if (rawMode[i.key] || !entries.every(([, x]) => typeof x === "string")) return jsonField(i, v);
    return (
      <div>
        <div className={s.kv}>
          {entries.map(([k, x]) => (
            <label key={k} style={{ display: "contents" }}>
              <code>{k}</code>
              <Input size="sm" aria-label={`${i.label}: ${k}`} value={String(x)} onChange={(e) => set(i.key, { ...v, [k]: e.target.value })} />
            </label>
          ))}
        </div>
        <Button size="sm" variant="ghost" icon={<Braces />} style={{ marginTop: 8 }} onClick={() => setRawMode((m) => ({ ...m, [i.key]: true }))}>JSON</Button>
      </div>
    );
  }

  function rolesMatrix(i: Item, v: Record<string, string[]>) {
    return (
      <div className={s.matrixWrap}>
        <Table caption="Rollar huquqlari" className="matrix">
          <thead><tr><Th>Huquq</Th>{Object.keys(ROLE_NAMES).map((r) => <Th key={r}>{ROLE_NAMES[r]}</Th>)}</tr></thead>
          <tbody>
            {permissions.map((p) => (
              <tr key={p}>
                <Td title={p}>
                  {permissionLabels[p] ?? p}
                  {showKeys && <div className="mono subtle" style={{ fontSize: 12 }}>{p}</div>}
                </Td>
                {Object.keys(ROLE_NAMES).map((r) => (
                  <Td key={r}>
                    <input type="checkbox" aria-label={`${ROLE_NAMES[r]}: ${permissionLabels[p] ?? p}`} checked={(v[r] ?? []).includes(p)} onChange={(e) => {
                      const cur = new Set(v[r] ?? []);
                      if (e.target.checked) cur.add(p); else cur.delete(p);
                      set(i.key, { ...v, [r]: [...cur] });
                    }} />
                  </Td>
                ))}
              </tr>
            ))}
          </tbody>
        </Table>
      </div>
    );
  }

  function field(i: Item) {
    const v = value(i);
    switch (i.type) {
      case "bool":
        return <Switch checked={Boolean(v)} onChange={(e) => set(i.key, e.target.checked)} label={v ? "Yoqilgan" : "O'chirilgan"} />;
      case "int":
        return (
          <Input type="number" aria-label={i.label} min={i.min ?? undefined} max={i.max ?? undefined} value={String(v ?? "")}
            onChange={(e) => set(i.key, Number.parseInt(e.target.value || "0", 10))} style={{ maxWidth: 200 }} />
        );
      case "string":
        if (/(_color|\.color)$/.test(i.key)) {
          const hex = /^#[0-9a-f]{6}$/i.test(String(v)) ? String(v) : "#2563eb";
          return (
            <div className={s.color}>
              <input type="color" aria-label={i.label} value={hex} onChange={(e) => set(i.key, e.target.value)} />
              <Input value={String(v ?? "")} aria-label={`${i.label} (#hex)`} onChange={(e) => set(i.key, e.target.value)} className="mono" />
            </div>
          );
        }
        return <Input aria-label={i.label} value={String(v ?? "")} onChange={(e) => set(i.key, e.target.value)} />;
      case "text":
        return <Textarea aria-label={i.label} rows={3} value={String(v ?? "")} onChange={(e) => set(i.key, e.target.value)} />;
      case "list":
        return listField(i, v);
      case "object":
        return objectField(i, v as Record<string, unknown>);
    }
  }

  const needle = q.trim().toLowerCase();
  const shown = needle
    ? items.filter((i) => i.label.toLowerCase().includes(needle) || i.key.toLowerCase().includes(needle))
    : groups.find(([g]) => g === active)?.[1] ?? [];

  return (
    <>
      <PageHeader
        title="Sozlamalar"
        description="Sayt matnlari, rang, bot xabarlari, qoidalar va huquqlar — hammasi shu yerdan boshqariladi."
        actions={<div style={{ width: 280, maxWidth: "100%" }}><Input type="search" placeholder="Sozlamani qidirish…" aria-label="Sozlamani qidirish" value={q} onChange={(e) => setQ(e.target.value)} leading={<Search />} /></div>}
      />
      {warnings.includes("teacher_course_manage") && (
        <Card padded style={{ marginBottom: 16, borderColor: "var(--warning, #b45309)" }} role="alert">
          <b>O&apos;qituvchilarda «{permissionLabels["course.manage"] ?? "course.manage"}» huquqi yoqilgan.</b>{" "}
          Bu eski standart: har bir o&apos;qituvchi barcha kurslarni (yashirinlari bilan) ko&apos;radi va kontentini ochadi.
          «Rollar huquqlari» bo&apos;limida o&apos;qituvchi uchun uni o&apos;chirib, «{permissionLabels["course.author"] ?? "course.author"}» ni yoqing.
        </Card>
      )}
      <div className={s.layout}>
        <nav className={s.groups} aria-label="Sozlama guruhlari">
          {groups.map(([g, list]) => (
            <button key={g} type="button" className={s.groupBtn} aria-current={!needle && g === active} onClick={() => { setActive(g); setQ(""); }}>
              <span>{GROUPS[g] ?? g}</span>
              {list.some((i) => i.key in draft) ? <span className={s.dotDirty} aria-label="o'zgargan" /> : <span className={s.groupCount}>{list.length}</span>}
            </button>
          ))}
        </nav>
        <div>
          <Card>
            {shown.length === 0 ? <EmptyState compact icon={<Search />} title="Topilmadi" /> : shown.map((i) => {
              const v = value(i);
              const changed = i.key in draft;
              const custom = !same(v, i.default);
              const wide = i.type === "list" || i.type === "object";
              return (
                <div key={i.key} className={cx(s.item, wide && s.wide, changed && s.changed)}>
                  <div className={s.label}>
                    <strong>{i.label}</strong>
                    {showKeys ? <span className={s.key}>{i.key}</span> : <span className="sr-only">{i.key}</span>}
                    <span className={s.badges}>
                      {i.public && <Badge tone="info">Ochiq</Badge>}
                      {changed ? <Badge tone="accent">Saqlanmagan</Badge> : custom && <Badge>O&apos;zgartirilgan</Badge>}
                      {needle && <Badge>{GROUPS[i.group] ?? i.group}</Badge>}
                      {(i.type === "list" || i.type === "object") && rawMode[i.key] && i.key !== "roles.permissions" && (
                        <button type="button" className="link" onClick={() => setRawMode((m) => ({ ...m, [i.key]: false }))}><List size={12} aria-hidden /> Forma</button>
                      )}
                    </span>
                  </div>
                  <div className={s.control}>{field(i)}</div>
                  <Button className={s.reset} variant="ghost" size="sm" iconOnly icon={<RotateCcw />} aria-label={`${i.label}: boshlang'ich qiymat`} title="Boshlang'ich qiymat" disabled={!custom && !changed} onClick={() => reset(i)} />
                </div>
              );
            })}
          </Card>
          {dirty > 0 && (
            <div className={s.saveBar} role="region" aria-label="Saqlash">
              <span>{dirty} ta saqlanmagan o&apos;zgarish</span>
              <Button variant="ghost" onClick={discard} style={{ color: "inherit" }}>Bekor qilish</Button>
              <Button onClick={save} loading={busy} disabled={hasInvalid}>Saqlash</Button>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
