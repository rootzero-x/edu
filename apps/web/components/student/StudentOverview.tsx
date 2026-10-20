"use client";

import Link from "next/link";
import { Award, CalendarClock, ClipboardCheck, PauseCircle, PieChart, Wallet } from "lucide-react";
import { Badge, Card, CardHeader, EmptyState, StatCard, Table, Td, Th } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { fmtDay } from "@/lib/enrollment";
import type { StudentOverview as Data } from "@/lib/student";
import { WEEKDAYS, fmtDate, fmtDateTime, fmtMoney, fmtTime, fmtWeekday } from "@/lib/format";
import { statusLabels, timezone } from "@/lib/settings";
import { STATUS_TONE } from "@/lib/status";
import s from "./overview.module.css";

/** Bitta o'quvchi bo'yicha bloklar: guruhlar, davomat, test natijalari, to'lov, sertifikatlar. */
export default function StudentOverview({ data, billingHref }: { data: Data; billingHref?: string }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const labels = statusLabels(st);
  const a = data.attendance;
  const next = data.groups.map((g) => g.next_session).filter(Boolean).sort()[0] ?? null;

  return (
    <div className={s.grid}>
      <section className={s.stats} aria-label="Qisqacha">
        <StatCard label="Davomat" value={a.rate == null ? "—" : `${a.rate}%`} icon={<PieChart />} progress={a.rate}
          tone={a.rate == null ? "accent" : a.rate >= 85 ? "success" : a.rate >= 65 ? "warning" : "danger"} hint={`So'nggi ${a.days} kun · kelmadi: ${a.counts.ABSENT ?? 0}`} />
        <StatCard label="Keyingi dars" value={next ? fmtTime(next, tz) : "—"} icon={<CalendarClock />} hint={next ? `${fmtDate(next, tz)} · ${fmtWeekday(next, tz)}` : "Rejalashtirilmagan"} />
        {data.balance && (
          <StatCard label="To'lov" value={data.balance.debt > 0 ? fmtMoney(data.balance.debt, data.balance.currency) : "Qarz yo'q"} icon={<Wallet />}
            tone={data.balance.debt > 0 ? "danger" : "success"} hint={data.balance.debt > 0 ? "Qarzdorlik" : `Balans: ${fmtMoney(data.balance.balance, data.balance.currency)}`} />
        )}
      </section>

      <Card>
        <CardHeader title="Guruhlar" description={`${data.groups.length} ta`} as="h3" />
        {data.groups.length === 0 ? <EmptyState compact icon={<CalendarClock />} title="Hozir guruhda emas" /> : (
          <ul className={s.groups}>
            {data.groups.map((g) => (
              <li key={g.id}>
                <div className={s.gMain}>
                  <Link href={`/dashboard/groups/${g.id}`} className="a"><strong>{g.name}</strong></Link>
                  <span className="subtle">{[g.course_title, g.teacher, g.room].filter(Boolean).join(" · ")}</span>
                  <span className={s.sched}>{g.schedule.map((x) => `${WEEKDAYS[x.weekday]} ${x.start}`).join(", ") || "Jadval kiritilmagan"}</span>
                </div>
                {g.status === "FROZEN"
                  ? <Badge tone="info" icon={<PauseCircle size={14} aria-hidden />}>Muzlatilgan · {fmtDay(g.frozen_until)} gacha</Badge>
                  : g.frozen_from ? <Badge>Muzlatish {fmtDay(g.frozen_from)} dan</Badge> : <Badge tone="success" dot>O&apos;qimoqda</Badge>}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card>
        <CardHeader title="So'nggi darslar" description={`So'nggi ${a.days} kun`} as="h3" />
        {a.recent.length === 0 ? <EmptyState compact icon={<ClipboardCheck />} title="Davomat belgilanmagan" /> : (
          <Table stack caption="So'nggi darslar davomati">
            <thead><tr><Th>Sana</Th><Th>Guruh</Th><Th>Holat</Th></tr></thead>
            <tbody>
              {a.recent.map((r, i) => (
                <tr key={i}>
                  <Td>{fmtDateTime(r.starts_at, tz)}</Td>
                  <Td label="Guruh">{r.group_name}{r.topic && <div className="subtle">{r.topic}</div>}</Td>
                  <Td label="Holat"><Badge tone={STATUS_TONE[r.status] ?? "neutral"}>{labels[r.status] ?? r.status}</Badge>{r.note && <div className="subtle">{r.note}</div>}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      <Card>
        <CardHeader title="Test natijalari" as="h3" />
        {data.quizzes.length === 0 ? <EmptyState compact icon={<ClipboardCheck />} title="Hali test topshirilmagan" /> : (
          <Table stack caption="Test natijalari">
            <thead><tr><Th>Test</Th><Th numeric>Natija</Th><Th>Sana</Th></tr></thead>
            <tbody>
              {data.quizzes.map((q, i) => (
                <tr key={i}>
                  <Td>{q.title}{q.is_final && <> <Badge tone="accent">Yakuniy</Badge></>}{q.course_title && <div className="subtle">{q.course_title}</div>}</Td>
                  <Td numeric label="Natija"><Badge tone={q.passed ? "success" : "danger"}>{q.percent ?? 0}%</Badge>{q.max_score != null && <div className="subtle">{q.score}/{q.max_score}</div>}</Td>
                  <Td label="Sana">{fmtDate(q.submitted_at, tz)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>

      {(data.certificates.length > 0 || billingHref) && (
        <Card>
          <CardHeader title="Sertifikatlar va to'lovlar" as="h3" />
          <ul className={s.links}>
            {data.certificates.map((c) => (
              <li key={c.code}><Award aria-hidden /><Link href={`/c/${c.code}`} className="a">{c.course_title}</Link><span className="subtle mono">{c.code} · {fmtDay(c.issued_at)}</span></li>
            ))}
            {billingHref && <li><Wallet aria-hidden /><Link href={billingHref} className="a">To&apos;lovlar tarixi</Link></li>}
          </ul>
        </Card>
      )}
    </div>
  );
}
