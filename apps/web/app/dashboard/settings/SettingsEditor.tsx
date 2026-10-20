"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Braces, History, List, Plus, RotateCcw, Search, Trash2 } from "lucide-react";
import { Badge, Button, Card, EmptyState, Input, PageHeader, Switch, Table, Td, Th, Textarea, cx, useConfirm, usePrompt, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { ROLE_LABELS } from "@/components/shell/nav";
import { call, errorText } from "@/lib/client";
import { obj } from "@/lib/settings";
import SettingHistory from "./SettingHistory";
import UploadsUsage from "./UploadsUsage";
import s from "./settings.module.css";

export type Item = {
  key: string; group: string; type: "int" | "bool" | "string" | "text" | "list" | "object";
  label: string; public: boolean; min: number | null; max: number | null; default: unknown; value: unknown;
};

/** Huquqlar matritsasidagi rollar tartibi (SUPER_ADMIN — har doim hamma huquq, ko'rsatilmaydi). */
const ROLE_ORDER = ["ADMIN", "TEACHER", "STUDENT"];
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
export default function SettingsEditor({ items, permissions, permissionLabels = {}, groupLabels = {}, warnings = [], showKeys = false, initialGroup, adminOnly = [] }: {
  items: Item[]; permissions: string[]; permissionLabels?: Record<string, string>; groupLabels?: Record<string, string>;
  /** Backend ogohlantirishlari (masalan, o'qituvchida course.manage qolgan). */
  warnings?: string[];
  /** Texnik kalitlar (sozlama kaliti, huquq kodi) faqat SUPER_ADMIN ga ko'rinadi; boshqalarga — tooltip. */
  showKeys?: boolean;
  /** ?group=billing — kerakli bo'lim ochiladi. */
  initialGroup?: string;
  /** Faqat ADMIN roliga beriladigan huquqlar (masalan, audit.view). */
  adminOnly?: string[];
}) {
  const GROUPS = groupLabels;
  const router = useRouter();
  const toast = useToast();
  const confirm = useConfirm();
  const prompt = usePrompt();
  const st = useSiteSettings();
  const roleNames = { ...ROLE_LABELS, ...obj<Record<string, string>>(st, "telegram.role_labels") };
  const [history, setHistory] = useState<Item | null>(null);
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
  // ?group=billing — "Boshlash" ro'yxatidagi havolalar to'g'ridan-to'g'ri kerakli bo'limni ochadi.
  const [active, setActive] = useState(groups.some(([g]) => g === initialGroup) ? initialGroup! : groups[0]?.[0] ?? "site");

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
    if (rawMode[i.key]) return jsonField(i, v);
    if (entries.length && entries.every(([, x]) => Array.isArray(x))) return nestedField(i, v as Record<string, unknown[]>);
    if (!entries.every(([, x]) => typeof x === "string")) return jsonField(i, v);
    // Standart qiymatda bo'lmagan kalitlarni qo'shish/o'chirish mumkin (masalan, tarjimalar).
    const fixed = new Set(Object.keys((i.default as Record<string, unknown>) ?? {}));
    async function addKey() {
      const r = await prompt({ title: "Yangi kalit", fields: [{ name: "k", label: "Kalit", required: true }, { name: "v", label: "Qiymat" }] });
      if (r?.k.trim()) set(i.key, { ...v, [r.k.trim()]: r.v });
    }
    return (
      <div>
        <div className={s.kv}>
          {entries.map(([k, x]) => (
            <div key={k} style={{ display: "contents" }}>
              <code id={`${i.key}-${k}`}>{k}</code>
              <div style={{ display: "flex", gap: 6 }}>
                <Input size="sm" aria-labelledby={`${i.key}-${k}`} value={String(x)} onChange={(e) => set(i.key, { ...v, [k]: e.target.value })} />
                {!fixed.has(k) && <Button size="sm" variant="dangerGhost" iconOnly aria-label={`${k} — o'chirish`} icon={<Trash2 />} onClick={() => { const n = { ...v }; delete n[k]; set(i.key, n); }} />}
              </div>
            </div>
          ))}
        </div>
        <div className={s.listActions} style={{ marginTop: 8 }}>
          {(fixed.size === 0 || entries.some(([k]) => !fixed.has(k))) && <Button size="sm" variant="soft" icon={<Plus />} onClick={addKey}>Kalit qo&apos;shish</Button>}
          <Button size="sm" variant="ghost" icon={<Braces />} onClick={() => setRawMode((m) => ({ ...m, [i.key]: true }))}>JSON</Button>
        </div>
      </div>
    );
  }

  /** Kalit → ro'yxat (masalan, rol → menyu qatorlari, til → bot buyruqlari): har bir kalit uchun qatorlar. */
  function nestedField(i: Item, v: Record<string, unknown[]>) {
    const update = (k: string, next: unknown[]) => set(i.key, { ...v, [k]: next });
    return (
      <div className={s.nested}>
        {Object.entries(v).map(([k, arr]) => {
          const objects = arr.length > 0 && arr.every((x) => x && typeof x === "object" && !Array.isArray(x));
          const keys = objects ? [...new Set(arr.flatMap((x) => Object.keys(x as object)))] : [];
          const blank = objects ? Object.fromEntries(keys.map((c) => [c, ""])) : arr.every((x) => Array.isArray(x)) ? [] : "";
          return (
            <fieldset key={k} className={s.nestedGroup}>
              <legend>{roleNames[k] ?? k}</legend>
              {arr.map((row, idx) => (
                <div key={idx} className={s.listRow} style={{ "--cols": objects ? keys.length : 1 } as React.CSSProperties}>
                  {objects ? keys.map((c) => (
                    <Input key={c} size="sm" placeholder={c} aria-label={`${k}: ${c} — ${idx + 1}`} value={String((row as Record<string, unknown>)[c] ?? "")}
                      onChange={(e) => update(k, arr.map((x, j) => (j === idx ? { ...(x as object), [c]: e.target.value } : x)))} />
                  )) : Array.isArray(row) ? (
                    <Input size="sm" aria-label={`${k} — ${idx + 1}-qator (vergul bilan)`} value={(row as unknown[]).join(", ")}
                      onChange={(e) => update(k, arr.map((x, j) => (j === idx ? e.target.value.split(",").map((t) => t.trim()).filter(Boolean) : x)))} />
                  ) : (
                    <Input size="sm" aria-label={`${k} — ${idx + 1}`} value={String(row ?? "")} onChange={(e) => update(k, arr.map((x, j) => (j === idx ? e.target.value : x)))} />
                  )}
                  <Button size="sm" variant="dangerGhost" iconOnly aria-label="O'chirish" icon={<Trash2 />} onClick={() => update(k, arr.filter((_, j) => j !== idx))} />
                </div>
              ))}
              <div><Button size="sm" variant="soft" icon={<Plus />} onClick={() => update(k, [...arr, blank])}>Qator qo&apos;shish</Button></div>
            </fieldset>
          );
        })}
        <div><Button size="sm" variant="ghost" icon={<Braces />} onClick={() => setRawMode((m) => ({ ...m, [i.key]: true }))}>JSON</Button></div>
      </div>
    );
  }

  function rolesMatrix(i: Item, v: Record<string, string[]>) {
    // Rollar — saqlangan matritsadan (yangi rol qo'shilsa yoki olib tashlansa forma o'zi moslashadi).
    const roles = [...ROLE_ORDER.filter((r) => r in v), ...Object.keys(v).filter((r) => !ROLE_ORDER.includes(r))];
    const name = (p: string) => permissionLabels[p] ?? p;
    return (
      <div className={s.matrixWrap}>
        <p className={s.hint} style={{ margin: 0, padding: "8px 12px" }}>Super-admin har doim barcha huquqlarga ega. O&apos;z rolingizdan sozlamalar yoki foydalanuvchilarni boshqarish huquqini olib tashlab bo&apos;lmaydi.</p>
        <Table caption="Rollar huquqlari" className="matrix" stack>
          <thead><tr><Th>Huquq</Th>{roles.map((r) => <Th key={r}>{roleNames[r] ?? r}</Th>)}</tr></thead>
          <tbody>
            {permissions.map((p) => (
              <tr key={p}>
                <Td title={p}>{name(p)}{showKeys && <div className={s.key}>{p}</div>}</Td>
                {roles.map((r) => (
                  <Td key={r} label={roleNames[r] ?? r}>
                    <input type="checkbox" aria-label={`${roleNames[r] ?? r}: ${name(p)}`} checked={(v[r] ?? []).includes(p)}
                      disabled={adminOnly.includes(p) && r !== "ADMIN"} title={adminOnly.includes(p) && r !== "ADMIN" ? "Faqat administratorga beriladi" : undefined} onChange={(e) => {
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
      case "int": {
        // Bo'sh maydon 0 emas: qiymat to'g'ri butun son va min..max oralig'ida bo'lmaguncha saqlanmaydi.
        const text = raw[i.key] ?? String(v ?? "");
        const err = invalid[i.key]
          ? (text.trim() === "" ? "Qiymat kiriting." : `Butun son${i.min != null && i.max != null ? `, ${i.min}..${i.max} oralig'ida` : ""} bo'lishi kerak.`)
          : null;
        return (
          <div>
            <Input type="number" inputMode="numeric" aria-label={i.label} aria-invalid={err ? true : undefined} min={i.min ?? undefined} max={i.max ?? undefined}
              value={text} style={{ maxWidth: 200 }}
              onChange={(e) => {
                const t = e.target.value;
                setRaw((r) => ({ ...r, [i.key]: t }));
                const n = /^-?\d+$/.test(t.trim()) ? Number(t) : NaN;
                const ok = Number.isInteger(n) && (i.min == null || n >= i.min) && (i.max == null || n <= i.max);
                setInvalid((x) => ({ ...x, [i.key]: !ok }));
                if (ok) set(i.key, n);
              }} />
            {err ? <div className={s.jsonErr} role="alert">{err}</div> : i.min != null && i.max != null && <div className={s.hint}>{i.min} – {i.max}</div>}
          </div>
        );
      }
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
          {!needle && active === "uploads" && <UploadsUsage showPaths={showKeys} />}
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
                  <div className={s.itemActions}>
                    <Button variant="ghost" size="sm" iconOnly icon={<History />} aria-label={`${i.label}: o'zgarishlar tarixi`} title="Tarix" onClick={() => setHistory(i)} />
                    <Button className={s.reset} variant="ghost" size="sm" iconOnly icon={<RotateCcw />} aria-label={`${i.label}: boshlang'ich qiymat`} title="Boshlang'ich qiymat" disabled={!custom && !changed} onClick={() => reset(i)} />
                  </div>
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
      <SettingHistory item={history} onClose={() => setHistory(null)} />
    </>
  );
}
