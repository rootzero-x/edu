"use client";

import { useCallback, useEffect, useState } from "react";
import { BarChart3, Inbox, PhoneCall, UserCheck, XCircle } from "lucide-react";
import { Card, CardHeader, EmptyState, Field, Input, Progress, StatCard, Table, Td, Th } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call } from "@/lib/client";
import { todayIso } from "@/lib/enrollment";
import type { FunnelRow, LeadStats } from "@/lib/leads";
import { timezone } from "@/lib/settings";
import s from "./leads.module.css";

/** Voronka: davr bo'yicha manba va kurs kesimida arizalar → yozilganlar ulushi, rad etish sabablari. */
export default function Funnel({ sources }: { sources: Record<string, string> }) {
  const tz = timezone(useSiteSettings());
  const [from, setFrom] = useState(() => todayIso(tz, -29));
  const [to, setTo] = useState(() => todayIso(tz));
  const [data, setData] = useState<LeadStats | null>(null);

  const load = useCallback(async () => {
    const r = await call<LeadStats>("GET", `leads/stats?from=${from}&to=${to}`);
    if (r.ok) setData(r.data);
  }, [from, to]);
  useEffect(() => { void load(); }, [load]);

  const row = (label: string, r: FunnelRow, key: string) => (
    <tr key={key}>
      <Td>{label}</Td>
      <Td numeric label="Arizalar">{r.total}</Td>
      <Td numeric label="Bog'lanildi">{r.contacted}</Td>
      <Td numeric label="Sinov darsi">{r.trial}</Td>
      <Td numeric label="Yozildi">{r.enrolled}</Td>
      <Td numeric label="Rad etdi">{r.lost}</Td>
      <Td label="Konversiya"><div className={s.conv}><Progress value={r.conversion ?? 0} label="Konversiya" /><b>{r.conversion == null ? "—" : `${r.conversion}%`}</b></div></Td>
    </tr>
  );
  const head = (first: string) => <thead><tr><Th>{first}</Th><Th numeric>Arizalar</Th><Th numeric>Bog&apos;lanildi</Th><Th numeric>Sinov</Th><Th numeric>Yozildi</Th><Th numeric>Rad etdi</Th><Th>Konversiya</Th></tr></thead>;

  return (
    <div className={s.funnel}>
      <div className={s.filters}>
        <Field label="Dan"><Input size="sm" type="date" value={from} max={to} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field label="Gacha"><Input size="sm" type="date" value={to} min={from} onChange={(e) => setTo(e.target.value)} /></Field>
      </div>
      {!data ? <p className="subtle">Yuklanmoqda…</p> : data.total.total === 0 ? (
        <Card><EmptyState icon={<BarChart3 />} title="Bu davrda ariza yo'q" /></Card>
      ) : (
        <>
          <section className={s.stats} aria-label="Voronka">
            <StatCard label="Arizalar" value={data.total.total} icon={<Inbox />} tone="info" />
            <StatCard label="Bog'lanildi" value={data.total.contacted} icon={<PhoneCall />} progress={(data.total.contacted / data.total.total) * 100} />
            <StatCard label="Yozildi" value={data.total.enrolled} icon={<UserCheck />} tone="success" hint={`Konversiya: ${data.total.conversion ?? 0}%`} progress={data.total.conversion} />
            <StatCard label="Rad etdi" value={data.total.lost} icon={<XCircle />} tone="danger" />
          </section>
          <Card>
            <CardHeader title="Manbalar bo'yicha" as="h3" />
            <Table stack caption="Manbalar bo'yicha voronka">{head("Manba")}<tbody>{data.by_source.map((r) => row(sources[r.source] ?? r.source, r, r.source))}</tbody></Table>
          </Card>
          <Card>
            <CardHeader title="Kurslar bo'yicha" as="h3" />
            <Table stack caption="Kurslar bo'yicha voronka">{head("Kurs")}<tbody>{data.by_course.map((r) => row(r.course_title ?? "Kurs ko'rsatilmagan", r, String(r.course_id)))}</tbody></Table>
          </Card>
          {data.lost_reasons.length > 0 && (
            <Card>
              <CardHeader title="Rad etish sabablari" as="h3" />
              <ul className={s.reasons}>
                {data.lost_reasons.map((r) => (
                  <li key={r.reason ?? "-"}><span>{r.reason ?? "Ko'rsatilmagan"}</span><Progress value={(r.count / data.total.lost) * 100} label={r.reason ?? ""} /><b>{r.count}</b></li>
                ))}
              </ul>
            </Card>
          )}
        </>
      )}
    </div>
  );
}
