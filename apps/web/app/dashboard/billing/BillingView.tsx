"use client";

import { useEffect, useState, useTransition } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { AlertTriangle, ChevronLeft, ChevronRight, Download, HandCoins, PiggyBank, Search, TrendingDown, UsersRound, Wallet } from "lucide-react";
import { Badge, Button, Card, EmptyState, Input, PageHeader, Select, StatCard, Switch, Table, Tabs, Td, Th, buttonClass, cx } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import EntryDialog from "@/components/billing/EntryDialog";
import { billingLabels, fmtYmd } from "@/lib/billing";
import { personName } from "@/lib/format";
import ReportPanel from "./ReportPanel";
import s from "./billing.module.css";

export type BalanceRow = {
  student_id: number; first_name: string; last_name: string | null; phone: string; active: boolean;
  balance: number; last_payment_date: string | null; groups: string[];
};
export type BalancesSummary = { students: number; debtors: number; total_debt: number; total_credit: number };
type Filters = { q: string; status: string; group_id: string; enrolled: string; sort: string };

const STATUS = [
  { id: "all", label: "Barchasi" }, { id: "debt", label: "Qarzdorlar" }, { id: "credit", label: "Oldindan to'langan" }, { id: "zero", label: "Nol balans" },
];

export default function BillingView({ rows, meta, summary, error, filters, groups }: {
  rows: BalanceRow[]; meta: { total: number; page: number; pages: number }; summary: BalancesSummary | null; error: string | null;
  filters: Filters; groups: { id: number; name: string }[];
}) {
  const L = billingLabels(useSiteSettings());
  const router = useRouter();
  const path = usePathname();
  const [tab, setTab] = useState("balances");
  const [search, setSearch] = useState(filters.q);
  const [loading, startTransition] = useTransition();
  const [pay, setPay] = useState<BalanceRow | null>(null);

  const go = (params: Partial<Filters & { page: string }>) => {
    const sp = new URLSearchParams({ ...filters, ...params });
    for (const [k, v] of [...sp.entries()]) if (!v || (k === "status" && v === "all")) sp.delete(k);
    startTransition(() => router.replace(`${path}${sp.size ? `?${sp}` : ""}`));
  };

  useEffect(() => {
    if (search === filters.q) return;
    const t = setTimeout(() => go({ q: search, page: "" }), 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  const csvQs = new URLSearchParams(Object.entries(filters).filter(([k, v]) => v && !(k === "status" && v === "all"))).toString();

  return (
    <>
      <PageHeader
        title="To'lovlar"
        description="O'quvchilar balansi, qarzdorlar va tushum"
        actions={tab === "balances" && (
          <a className={buttonClass({ variant: "secondary" })} href={`/api/export/billing/balances.csv${csvQs ? `?${csvQs}` : ""}`} download>
            <Download aria-hidden /> CSV (Excel)
          </a>
        )}
      />
      <div style={{ marginBottom: 16 }}>
        <Tabs label="Bo'lim" value={tab} onChange={setTab} items={[{ id: "balances", label: "Balanslar" }, { id: "report", label: "Tushum hisoboti" }]} />
      </div>

      {tab === "report" ? <ReportPanel /> : (
        <>
          {summary && (
            <section className={s.stats} aria-label="Xulosa">
              <StatCard label="Qarzdorlar" value={summary.debtors} icon={<AlertTriangle />} tone={summary.debtors ? "danger" : "success"} hint={`${summary.students} ta o'quvchidan`} />
              <StatCard label="Jami qarz" value={L.money(summary.total_debt)} icon={<TrendingDown />} tone={summary.total_debt ? "danger" : "success"} />
              <StatCard label="Oldindan to'langan" value={L.money(summary.total_credit)} icon={<PiggyBank />} tone="success" />
              <StatCard label="O'quvchilar" value={summary.students} icon={<UsersRound />} tone="info" />
            </section>
          )}

          <div className={s.filters}>
            <Tabs variant="pills" label="Holat bo'yicha" value={filters.status || "all"} onChange={(id) => go({ status: id, page: "" })} items={STATUS} />
            <div className={s.filterRow}>
              <div className={s.search}>
                <Input type="search" placeholder="Ism yoki telefon…" aria-label="Qidirish" value={search} onChange={(e) => setSearch(e.target.value)} leading={<Search />} />
              </div>
              <Select aria-label="Guruh" value={filters.group_id} onChange={(e) => go({ group_id: e.target.value, page: "" })} className={s.select}>
                <option value="">Barcha guruhlar</option>
                {groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
              </Select>
              <Select aria-label="Saralash" value={filters.sort} onChange={(e) => go({ sort: e.target.value, page: "" })} className={s.select}>
                <option value="">Avval qarzdorlar</option>
                <option value="-balance">Avval ortiqcha to&apos;laganlar</option>
                <option value="name">Ism bo&apos;yicha</option>
                <option value="last_payment">Eng uzoq to&apos;lamaganlar</option>
              </Select>
              <Switch label="Faqat guruhdagilar" checked={filters.enrolled === "1"} onChange={(e) => go({ enrolled: e.target.checked ? "1" : "", page: "" })} />
            </div>
          </div>

          <Card style={{ opacity: loading ? 0.6 : 1, transition: "opacity 150ms" }} aria-busy={loading}>
            {error ? (
              <EmptyState icon={<AlertTriangle />} title="Yuklab bo'lmadi">{error}</EmptyState>
            ) : rows.length === 0 ? (
              <EmptyState icon={<Wallet />} title="Hech kim topilmadi">{filters.q ? "Qidiruv so'zini o'zgartirib ko'ring." : "Bu filtr bo'yicha o'quvchi yo'q."}</EmptyState>
            ) : (
              <Table stack caption="O'quvchilar balansi">
                <thead><tr><Th>O&apos;quvchi</Th><Th>Telefon</Th><Th>Guruhlar</Th><Th numeric>Balans</Th><Th>Oxirgi to&apos;lov</Th><Th actions><span className="sr-only">Amallar</span></Th></tr></thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.student_id}>
                      <Td>
                        <Link href={`/dashboard/billing/${r.student_id}`} className="a"><strong style={{ fontWeight: 600 }}>{personName(r)}</strong></Link>
                        {!r.active && <> <Badge tone="danger">Bloklangan</Badge></>}
                      </Td>
                      <Td label="Telefon" className="mono">{r.phone}</Td>
                      <Td label="Guruhlar">{r.groups.length ? r.groups.join(", ") : <span className="subtle">—</span>}</Td>
                      <Td label="Balans" numeric>
                        <span className={cx(s.money, r.balance < 0 && s.neg, r.balance > 0 && s.pos)}>{L.money(r.balance)}</span>
                      </Td>
                      <Td label="Oxirgi to'lov">{r.last_payment_date ? fmtYmd(r.last_payment_date) : <span className="subtle">—</span>}</Td>
                      <Td actions>
                        <Button size="sm" variant="secondary" icon={<HandCoins />} onClick={() => setPay(r)}>To&apos;lov</Button>
                      </Td>
                    </tr>
                  ))}
                </tbody>
              </Table>
            )}
          </Card>
          {meta.pages > 1 && (
            <nav className="row" style={{ justifyContent: "center", marginTop: 16 }} aria-label="Sahifalar">
              <Button variant="secondary" size="sm" icon={<ChevronLeft />} disabled={meta.page <= 1} onClick={() => go({ page: String(meta.page - 1) })}>Oldingi</Button>
              <span className="muted" aria-current="page">{meta.page} / {meta.pages}</span>
              <Button variant="secondary" size="sm" disabled={meta.page >= meta.pages} onClick={() => go({ page: String(meta.page + 1) })}>Keyingi<ChevronRight size={16} aria-hidden /></Button>
            </nav>
          )}
        </>
      )}

      <EntryDialog
        mode="payment"
        open={pay !== null}
        onClose={() => setPay(null)}
        student={{ id: pay?.student_id ?? 0, name: pay ? personName(pay) : "" }}
        balance={pay?.balance}
        onDone={() => router.refresh()}
      />
    </>
  );
}
