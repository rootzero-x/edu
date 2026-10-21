"use client";

import { useCallback, useEffect, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { AlertTriangle, BarChart3, ChevronLeft, ChevronRight, Inbox, List, PhoneCall, Plus, Search } from "lucide-react";
import { Badge, Button, Card, EmptyState, Input, PageHeader, Select, Table, Tabs, Td, Th, cx, usePrompt, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDay, todayIso } from "@/lib/enrollment";
import { fmtDate } from "@/lib/format";
import { LEAD_STATUSES, LEAD_STATUS_FALLBACK, LEAD_TONE, OPEN_STATUSES, type Lead, type LeadList, type LeadOptions } from "@/lib/leads";
import { obj, timezone } from "@/lib/settings";
import LeadCard from "./LeadCard";
import Funnel from "./Funnel";
import s from "./leads.module.css";

type Filters = { status: string; source: string; course_id: string; q: string; due: string; page: number };
const DEFAULT: Filters = { status: OPEN_STATUSES.join(","), source: "", course_id: "", q: "", due: "", page: 1 };

/** Arizalar: ochiq arizalar ro'yxati, "bugun qo'ng'iroq qilish", kartochka va voronka. */
export default function LeadsView({ initial, options, openId }: { initial: LeadList | null; options: LeadOptions; openId: number | null }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const toast = useToast();
  const prompt = usePrompt();
  const router = useRouter();
  const path = usePathname();
  const statusLabels = { ...LEAD_STATUS_FALLBACK, ...obj<Record<string, string>>(st, "leads.status_labels") };
  const sources = obj<Record<string, string>>(st, "leads.sources");
  const [tab, setTab] = useState("list");
  const [f, setF] = useState<Filters>(DEFAULT);
  const [search, setSearch] = useState("");
  const [data, setData] = useState<LeadList | null>(initial);
  const [loading, setLoading] = useState(false);
  const [card, setCard] = useState<number | null>(openId);
  const today = todayIso(tz);

  const load = useCallback(async (filters: Filters) => {
    setLoading(true);
    const qs = new URLSearchParams({ page: String(filters.page) });
    for (const k of ["status", "source", "course_id", "q", "due"] as const) if (filters[k]) qs.set(k, filters[k]);
    const r = await call<LeadList>("GET", `leads?${qs}`);
    setLoading(false);
    if (r.ok) setData(r.data);
    else toast.error("Yuklanmadi", errorText(r.data));
  }, [toast]);

  const apply = (patch: Partial<Filters>) => { const next = { ...f, page: 1, ...patch }; setF(next); void load(next); };
  useEffect(() => {
    if (search === f.q) return;
    const t = setTimeout(() => apply({ q: search }), 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);
  useEffect(() => setCard(openId), [openId]);
  // Sahifaga qaytilganda (30 s mijoz keshidagi eski ro'yxat o'rniga) yangi ma'lumot olinadi.
  useEffect(() => { void load(DEFAULT); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const openCard = (id: number | null) => {
    setCard(id);
    router.replace(id ? `${path}?id=${id}` : path, { scroll: false });
  };

  const create = () => prompt({
    title: "Yangi ariza",
    description: "Telefon qo'ng'irog'i, o'zi kelgan yoki tanish orqali kelgan murojaat",
    fields: [
      { name: "name", label: "Ism", required: true },
      { name: "phone", label: "Telefon", type: "tel", required: true, placeholder: "90 123 45 67" },
      { name: "source", label: "Manba", type: "select", required: true, defaultValue: "phone", options: Object.entries(sources).filter(([k]) => k !== "site" && k !== "bot" && k !== "import").map(([value, label]) => ({ value, label })) },
      { name: "course_id", label: "Qiziqqan kurs", type: "select", options: [{ value: "", label: "— bilmaydi —" }, ...options.courses.map((c) => ({ value: String(c.id), label: c.title }))] },
      { name: "next_contact_on", label: "Qachon bog'lanish kerak", type: "date", defaultValue: today },
      { name: "comment", label: "Izoh", type: "textarea" },
    ],
    submitText: "Saqlash",
    onSubmit: async (v) => {
      const r = await call<{ lead: Lead; existing_lead_id?: number }>("POST", "leads", { ...v, course_id: v.course_id ? Number(v.course_id) : null, comment: v.comment || null, next_contact_on: v.next_contact_on || null });
      if (!r.ok) {
        if (r.data.existing_lead_id) { openCard(r.data.existing_lead_id); toast.info("Bu raqam bilan ochiq ariza bor", "Mavjud ariza ochildi"); return; }
        return errorText(r.data);
      }
      toast.success("Ariza qo'shildi", v.name);
      void load(f);
      openCard(r.data.lead.id);
    },
  });

  const counts = data?.counts ?? {};
  const openCount = OPEN_STATUSES.reduce((a, k) => a + (counts[k] ?? 0), 0);
  const statusTabs = [
    { id: OPEN_STATUSES.join(","), label: "Ochiq", count: openCount },
    ...LEAD_STATUSES.map((k) => ({ id: k, label: statusLabels[k] ?? k, count: counts[k] ?? 0 })),
    { id: "", label: "Barchasi" },
  ];

  return (
    <>
      <PageHeader title="Arizalar" description="Saytdan, botdan va telefon orqali kelgan murojaatlar" actions={<Button icon={<Plus />} onClick={create}>Yangi ariza</Button>} />
      <Tabs label="Arizalar bo'limlari" value={tab} onChange={setTab} items={[{ id: "list", label: "Ro'yxat", icon: <List size={16} aria-hidden /> }, { id: "funnel", label: "Voronka", icon: <BarChart3 size={16} aria-hidden /> }]} />
      {tab === "funnel" ? <Funnel sources={sources} /> : (
        <>
          <div className={s.toolbar}>
            <Tabs variant="pills" label="Holat bo'yicha" value={f.status} onChange={(id) => apply({ status: id })} items={statusTabs} />
          </div>
          <div className={s.filters}>
            <Button size="sm" variant={f.due === "today" ? "primary" : "secondary"} icon={<PhoneCall />} aria-pressed={f.due === "today"} onClick={() => apply({ due: f.due === "today" ? "" : "today" })}>
              Bugun qo&apos;ng&apos;iroq{data ? ` · ${data.due_today}` : ""}
            </Button>
            <Select size="sm" aria-label="Manba" value={f.source} onChange={(e) => apply({ source: e.target.value })}>
              <option value="">Barcha manbalar</option>
              {Object.entries(sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </Select>
            <Select size="sm" aria-label="Kurs" value={f.course_id} onChange={(e) => apply({ course_id: e.target.value })}>
              <option value="">Barcha kurslar</option>
              {options.courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </Select>
            <div className={s.search}><Input size="sm" type="search" placeholder="Ism yoki telefon…" aria-label="Arizalarni qidirish" value={search} onChange={(e) => setSearch(e.target.value)} leading={<Search />} /></div>
          </div>
          <Card style={{ opacity: loading ? 0.6 : 1, transition: "opacity 150ms" }} aria-busy={loading}>
            {!data ? <EmptyState icon={<AlertTriangle />} title="Ma'lumotni yuklab bo'lmadi" action={<Button onClick={() => load(f)}>Qayta urinish</Button>} />
              : data.data.length === 0 ? (
                <EmptyState icon={<Inbox />} title={f.due === "today" ? "Bugun qo'ng'iroq qilinadigan ariza yo'q" : "Ariza yo'q"}>
                  Saytdagi forma va botdagi «Ariza qoldirish» tugmasi orqali kelgan arizalar shu yerda ko&apos;rinadi.
                </EmptyState>
              ) : (
                <Table stack caption="Arizalar">
                  <thead><tr><Th>Ism</Th><Th>Kurs</Th><Th>Manba</Th><Th>Holat</Th><Th>Bog&apos;lanish</Th><Th>Mas&apos;ul</Th><Th>Kelgan</Th></tr></thead>
                  <tbody>
                    {data.data.map((l) => {
                      const overdue = l.next_contact_on && l.next_contact_on < today && OPEN_STATUSES.includes(l.status);
                      return (
                        <tr key={l.id} className={s.row} onClick={() => openCard(l.id)}>
                          <Td>
                            <button type="button" className={s.nameBtn} onClick={(e) => { e.stopPropagation(); openCard(l.id); }}>
                              <b>{l.name}</b><span className="mono subtle">{l.phone}</span>
                            </button>
                          </Td>
                          <Td label="Kurs">{l.course?.title ?? <span className="subtle">—</span>}</Td>
                          <Td label="Manba">{sources[l.source] ?? l.source}</Td>
                          <Td label="Holat">
                            <Badge tone={LEAD_TONE[l.status] ?? "neutral"}>{statusLabels[l.status] ?? l.status}</Badge>
                            {l.trial && l.status === "TRIAL" && <div className="subtle">{fmtDate(l.trial.starts_at, tz)} · {l.trial.group_name}</div>}
                            {l.lost_reason && <div className="subtle">{l.lost_reason}</div>}
                          </Td>
                          <Td label="Bog'lanish"><span className={cx(overdue && s.overdue, l.next_contact_on === today && s.due)}>{l.next_contact_on ? fmtDay(l.next_contact_on) : "—"}</span></Td>
                          <Td label="Mas'ul">{l.owner?.name ?? <span className="subtle">—</span>}</Td>
                          <Td label="Kelgan">{fmtDate(l.created_at, tz)}</Td>
                        </tr>
                      );
                    })}
                  </tbody>
                </Table>
              )}
          </Card>
          {data && data.meta.pages > 1 && (
            <nav className="row" style={{ justifyContent: "center", marginTop: 16 }} aria-label="Sahifalar">
              <Button variant="secondary" size="sm" icon={<ChevronLeft />} disabled={f.page <= 1} onClick={() => { const n = { ...f, page: f.page - 1 }; setF(n); void load(n); }}>Oldingi</Button>
              <span className="muted" aria-current="page">{data.meta.page} / {data.meta.pages}</span>
              <Button variant="secondary" size="sm" disabled={f.page >= data.meta.pages} onClick={() => { const n = { ...f, page: f.page + 1 }; setF(n); void load(n); }}>Keyingi<ChevronRight size={16} aria-hidden /></Button>
            </nav>
          )}
        </>
      )}
      <LeadCard id={card} options={options} onClose={() => openCard(null)} onChanged={() => { void load(f); router.refresh(); }} />
    </>
  );
}
