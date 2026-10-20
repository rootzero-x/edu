"use client";

import { useCallback, useEffect, useState } from "react";
import { AlertTriangle, Ban, Banknote, Download, Receipt, Scale, TrendingDown } from "lucide-react";
import { Card, CardHeader, EmptyState, Field, Input, Skeleton, StatCard, Table, Td, Th, buttonClass } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { billingLabels, fmtYmd, isoDateIn } from "@/lib/billing";
import { call, errorText } from "@/lib/client";
import { timezone } from "@/lib/settings";
import s from "./billing.module.css";

type Report = {
  period: { from: string; to: string };
  income: number; payments_count: number; charged: number; adjusted: number;
  voided: { count: number; amount: number };
  by_method: { method: string | null; count: number; amount: number }[];
  by_day: { date: string; count: number; amount: number }[];
  debtors: number; total_debt: number;
};

/** Davr bo'yicha tushum: to'lovlar (usul va kun kesimida), hisoblangan summa, qarz. */
export default function ReportPanel() {
  const st = useSiteSettings();
  const L = billingLabels(st);
  const tz = timezone(st);
  const today = isoDateIn(new Date(), tz);
  const [p, setP] = useState({ from: `${today.slice(0, 8)}01`, to: today });
  const [data, setData] = useState<Report | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (signal: AbortSignal) => {
    setError(null);
    try {
      const r = await call<Report>("GET", `billing/report?from=${p.from}&to=${p.to}`, undefined, { signal });
      if (r.ok) setData(r.data);
      else setError(errorText(r.data));
    } catch {
      /* bekor qilingan so'rov */
    }
  }, [p]);

  useEffect(() => {
    if (!p.from || !p.to) return;
    const c = new AbortController();
    void load(c.signal);
    return () => c.abort();
  }, [load, p]);

  const qs = `from=${p.from}&to=${p.to}`;
  return (
    <>
      <div className={s.period}>
        <Field label="Boshlanish"><Input type="date" value={p.from} max={p.to} onChange={(e) => setP({ ...p, from: e.target.value })} /></Field>
        <Field label="Tugash"><Input type="date" value={p.to} min={p.from} max={today} onChange={(e) => setP({ ...p, to: e.target.value })} /></Field>
        <a className={buttonClass({ variant: "secondary" })} href={`/api/export/billing/entries.csv?${qs}`} download style={{ flex: "0 0 auto" }}>
          <Download aria-hidden /> To&apos;lovlar CSV
        </a>
      </div>

      {error ? (
        <Card><EmptyState icon={<AlertTriangle />} title="Hisobot yuklanmadi">{error}</EmptyState></Card>
      ) : !data ? (
        <Card style={{ padding: 16, display: "grid", gap: 10 }}><Skeleton height={60} /><Skeleton height={160} /></Card>
      ) : (
        <>
          <section className={s.stats} aria-label="Davr xulosasi">
            <StatCard label="Tushum" value={L.money(data.income)} icon={<Banknote />} tone="success" hint={`${data.payments_count} ta to'lov · ${fmtYmd(data.period.from)} – ${fmtYmd(data.period.to)}`} />
            <StatCard label="Hisoblandi" value={L.money(data.charged)} icon={<Receipt />} tone="info" hint="Kunlik hisoblashlar yig'indisi" />
            <StatCard label="Tuzatishlar" value={L.signed(data.adjusted)} icon={<Scale />} tone="accent" />
            <StatCard label="Hozirgi qarz" value={L.money(data.total_debt)} icon={<TrendingDown />} tone={data.total_debt ? "danger" : "success"} hint={`${data.debtors} ta qarzdor`} />
            {data.voided.count > 0 && <StatCard label="Bekor qilingan" value={L.money(data.voided.amount)} icon={<Ban />} tone="warning" hint={`${data.voided.count} ta to'lov`} />}
          </section>
          <div className={s.cols}>
            <Card>
              <CardHeader title="To'lov usullari" />
              {data.by_method.length === 0 ? <EmptyState compact icon={<Banknote />} title="Bu davrda to'lov yo'q" /> : (
                <Table stack caption="To'lov usullari bo'yicha">
                  <thead><tr><Th>Usul</Th><Th numeric>Soni</Th><Th numeric>Summa</Th></tr></thead>
                  <tbody>
                    {data.by_method.map((m) => (
                      <tr key={m.method ?? "-"}>
                        <Td>{m.method ? L.methods[m.method] ?? m.method : "—"}</Td>
                        <Td label="Soni" numeric>{m.count}</Td>
                        <Td label="Summa" numeric><span className={s.money}>{L.money(m.amount)}</span></Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
            <Card>
              <CardHeader title="Kunlar bo'yicha" />
              {data.by_day.length === 0 ? <EmptyState compact icon={<Receipt />} title="Bu davrda to'lov yo'q" /> : (
                <Table stack caption="Kunlar bo'yicha tushum">
                  <thead><tr><Th>Sana</Th><Th numeric>Soni</Th><Th numeric>Summa</Th></tr></thead>
                  <tbody>
                    {[...data.by_day].reverse().map((d) => (
                      <tr key={d.date}>
                        <Td>{fmtYmd(d.date)}</Td>
                        <Td label="Soni" numeric>{d.count}</Td>
                        <Td label="Summa" numeric><span className={s.money}>{L.money(d.amount)}</span></Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
              )}
            </Card>
          </div>
        </>
      )}
    </>
  );
}
