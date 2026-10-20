"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import { usePathname } from "next/navigation";
import { AlertTriangle, ChevronRight, RotateCcw, ScrollText, SlidersHorizontal } from "lucide-react";
import { Badge, Button, Card, EmptyState, Field, Input, PageHeader, Select, cx } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { ROLE_LABELS } from "@/components/shell/nav";
import { call, errorText } from "@/lib/client";
import { fmtDate, fmtTime } from "@/lib/format";
import { obj, timezone } from "@/lib/settings";
import s from "./audit.module.css";

type Diff = { field: string; from: unknown; to: unknown };
type Row = {
  id: number; created_at: string; actor: { id: number; name: string; role: string } | null; action: string; action_label: string | null;
  entity: string; entity_label: string; entity_id: number | null; entity_name: string | null; ip: string | null; diff: Diff[]; note: string | null;
};
type Facets = { actions: string[]; entities: string[]; actors: { id: number; name: string; role: string }[]; action_labels: Record<string, string>; entity_labels: Record<string, string> };
type Filters = { actor_id: string; entity: string; entity_id: string; action: string; from: string; to: string };

/** Obyekt sahifasiga havola (bo'lsa). */
const entityHref = (entity: string, id: number | null) => {
  if (!id) return null;
  return ({ group: `/dashboard/groups/${id}`, session: `/dashboard/sessions/${id}`, course: `/dashboard/courses/${id}`, lesson: `/dashboard/lessons/${id}`,
    assignment: `/dashboard/homework/${id}`, quiz: `/dashboard/quizzes/${id}/edit`, billing: `/dashboard/billing/${id}` } as Record<string, string>)[entity] ?? null;
};

function show(v: unknown) {
  if (v === null || v === undefined || v === "") return <span className={s.empty}>—</span>;
  if (typeof v === "boolean") return v ? "ha" : "yo'q";
  return String(v);
}

export default function AuditView({ initial }: { initial: Filters }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const path = usePathname();
  const roles = { ...ROLE_LABELS, ...obj<Record<string, string>>(st, "telegram.role_labels") };
  const [f, setF] = useState<Filters>(initial);
  const [page, setPage] = useState(1);
  const [facets, setFacets] = useState<Facets | null>(null);
  const [data, setData] = useState<{ data: Row[]; meta: { page: number; pages: number; total: number } } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<Set<number>>(new Set());
  const [showFilters, setShowFilters] = useState(false);

  const qs = useMemo(() => new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString(), [f]);
  useEffect(() => { call<Facets>("GET", "audit/facets").then((r) => r.ok && setFacets(r.data)); }, []);
  const load = useCallback(async () => {
    const r = await call<NonNullable<typeof data>>("GET", `audit?page=${page}${qs ? `&${qs}` : ""}`);
    if (!r.ok) { setError(errorText(r.data)); return; }
    setError(null);
    setData(r.data);
  }, [qs, page]);
  // Filtrlar URL'da saqlanadi (havolani ulashish uchun) — sahifani qayta yuklamasdan.
  useEffect(() => { void load(); window.history.replaceState(null, "", qs ? `${path}?${qs}` : path); }, [load]); // eslint-disable-line react-hooks/exhaustive-deps

  const set = (k: keyof Filters) => (e: { target: { value: string } }) => { setF((x) => ({ ...x, [k]: e.target.value, ...(k === "entity" ? { entity_id: "" } : {}) })); setPage(1); };
  const actionLabel = (a: string) => facets?.action_labels[a] ?? a;
  // Amallar guruhlanadi: "group" (barcha group.*) va aniq amallar.
  const prefixes = useMemo(() => [...new Set((facets?.actions ?? []).map((a) => a.split(".")[0]))], [facets]);
  const toggle = (id: number) => setOpen((o) => { const n = new Set(o); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  const reset = () => { setF({ actor_id: "", entity: "", entity_id: "", action: "", from: "", to: "" }); setPage(1); };

  return (
    <>
      <PageHeader title="Audit jurnali" description="Kim, qachon va nimani o'zgartirdi — oldingi va yangi qiymatlar bilan"
        actions={<Button variant="secondary" className={s.filterToggle} icon={<SlidersHorizontal />} aria-expanded={showFilters} aria-controls="audit-filters" onClick={() => setShowFilters((v) => !v)}>
          Filtrlar{Object.values(f).filter(Boolean).length ? ` · ${Object.values(f).filter(Boolean).length}` : ""}
        </Button>} />
      <Card className={s.filters} id="audit-filters" data-open={showFilters || undefined}>
        <Field label="Kim"><Select size="sm" value={f.actor_id} onChange={set("actor_id")}><option value="">Barchasi</option>{facets?.actors.map((a) => <option key={a.id} value={a.id}>{a.name} ({roles[a.role] ?? a.role})</option>)}</Select></Field>
        <Field label="Amal"><Select size="sm" value={f.action} onChange={set("action")}>
          <option value="">Barchasi</option>
          {prefixes.map((p) => (
            <optgroup key={p} label={facets?.entity_labels[p] ?? p}>
              <option value={p}>Barcha «{facets?.entity_labels[p] ?? p}» amallari</option>
              {facets?.actions.filter((a) => a.startsWith(`${p}.`)).map((a) => <option key={a} value={a}>{actionLabel(a)}</option>)}
            </optgroup>
          ))}
        </Select></Field>
        <Field label="Obyekt"><Select size="sm" value={f.entity} onChange={set("entity")}><option value="">Barchasi</option>{facets?.entities.map((e) => <option key={e} value={e}>{facets.entity_labels[e] ?? e}</option>)}</Select></Field>
        {f.entity && <Field label="Obyekt ID"><Input size="sm" inputMode="numeric" value={f.entity_id} onChange={set("entity_id")} /></Field>}
        <Field label="Dan"><Input size="sm" type="date" value={f.from} max={f.to || undefined} onChange={set("from")} /></Field>
        <Field label="Gacha"><Input size="sm" type="date" value={f.to} min={f.from || undefined} onChange={set("to")} /></Field>
        <div><Button size="sm" variant="ghost" icon={<RotateCcw />} onClick={reset} disabled={!qs}>Tozalash</Button></div>
      </Card>

      {error ? <Card><EmptyState icon={<AlertTriangle />} title="Yuklanmadi">{error}</EmptyState></Card> : !data ? (
        <Card padded><p className="subtle" style={{ margin: 0 }}>Yuklanmoqda…</p></Card>
      ) : data.data.length === 0 ? (
        <Card><EmptyState icon={<ScrollText />} title="Yozuv topilmadi" /></Card>
      ) : (
        <Card>
          <p className="sr-only" aria-live="polite">{data.meta.total} ta yozuv</p>
          <ul className={s.list}>
            {data.data.map((r) => {
              const isOpen = open.has(r.id);
              const href = entityHref(r.entity, r.entity_id);
              return (
                <li key={r.id} className={cx(s.row, isOpen && s.open)}>
                  <button type="button" className={s.summary} aria-expanded={isOpen} aria-controls={`audit-${r.id}`} onClick={() => toggle(r.id)}>
                    <span className={s.when}>{fmtDate(r.created_at, tz)} {fmtTime(r.created_at, tz)}</span>
                    <span className={s.what}>
                      <b>{r.action_label ?? r.action}</b>
                      <span>{r.actor ? `${r.actor.name} · ${roles[r.actor.role] ?? r.actor.role}` : "Tizim"} → {r.entity_label}{r.entity_name ? `: ${r.entity_name}` : r.entity_id ? ` #${r.entity_id}` : ""}</span>
                    </span>
                    <span style={{ display: "flex", alignItems: "center", gap: 8 }}>
                      {r.diff.length > 0 && <Badge>{r.diff.length}</Badge>}
                      <ChevronRight className={s.chev} aria-hidden />
                    </span>
                  </button>
                  {isOpen && (
                    <div id={`audit-${r.id}`} className={s.details}>
                      {r.diff.length > 0 ? (
                        <table className={s.diff}>
                          <thead><tr><th scope="col">Maydon</th><th scope="col">Oldin</th><th scope="col">Keyin</th></tr></thead>
                          <tbody>
                            {r.diff.map((d, i) => (
                              <tr key={i}><td>{d.field}</td><td className={d.from != null ? s.from : undefined}>{show(d.from)}</td><td className={s.to}>{show(d.to)}</td></tr>
                            ))}
                          </tbody>
                        </table>
                      ) : <span className="subtle">Qo&apos;shimcha ma&apos;lumot yo&apos;q</span>}
                      {r.note && <div><b>Sabab:</b> {r.note}</div>}
                      <div className={s.code}>{r.action}{r.ip ? ` · IP ${r.ip}` : ""}{href ? <> · <Link className="a" href={href}>Ochish</Link></> : null}</div>
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
          {data.meta.pages > 1 && (
            <div className={s.pager}>
              <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Oldingi</Button>
              <span className="subtle">{data.meta.page} / {data.meta.pages} · {data.meta.total} ta</span>
              <Button size="sm" variant="secondary" disabled={page >= data.meta.pages} onClick={() => setPage(page + 1)}>Keyingi</Button>
            </div>
          )}
        </Card>
      )}
    </>
  );
}
