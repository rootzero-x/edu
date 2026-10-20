"use client";

import Link from "next/link";
import { Inbox, Phone, Send, UserRound } from "lucide-react";
import { Badge, Card, CardHeader, EmptyState, PageHeader } from "@/components/ui";
import StudentOverview from "@/components/student/StudentOverview";
import { useSiteSettings } from "@/components/SiteProvider";
import { ROLE_LABELS } from "@/components/shell/nav";
import { enrollmentLabels, fmtDay, type TimelineRow } from "@/lib/enrollment";
import type { StudentOverview as Overview } from "@/lib/student";
import { fmtDate, personName } from "@/lib/format";
import { obj, timezone } from "@/lib/settings";
import { Timeline } from "../../groups/[id]/Members";
import s from "./profile.module.css";

export type ProfileData = {
  user: { id: number; phone: string; first_name: string; last_name: string | null; role: string; active: boolean; created_at: string | null; telegram: boolean };
  can_manage: boolean;
  overview: Overview | null;
  timeline: TimelineRow[];
  leads: { id: number; status: string; source: string; created_at: string }[];
};

/** O'quvchi (yoki boshqa foydalanuvchi) profili: kontaktlar, a'zolik tarixi, davomat, natijalar, to'lov, arizalar. */
export default function ProfileView({ data, canBilling, canUsers, canLeads }: { data: ProfileData; canBilling: boolean; canUsers: boolean; canLeads: boolean }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const roles = { ...ROLE_LABELS, ...obj<Record<string, string>>(st, "telegram.role_labels") };
  const leadLabels = obj<Record<string, string>>(st, "leads.status_labels");
  const u = data.user;
  const isStudent = u.role === "STUDENT";

  return (
    <>
      <PageHeader
        back={{ href: canUsers ? "/dashboard/users" : "/dashboard/groups", label: canUsers ? "Foydalanuvchilar" : "Guruhlar" }}
        title={personName(u)}
        badge={<>{" "}<Badge tone="info">{roles[u.role] ?? u.role}</Badge>{!u.active && <> <Badge tone="danger">Bloklangan</Badge></>}</>}
        description={u.created_at ? `Ro'yxatdan o'tgan: ${fmtDate(u.created_at, tz)}` : undefined}
      />
      <div className={s.layout}>
        <aside className={s.side}>
          <Card>
            <CardHeader title="Aloqa" as="h3" />
            <ul className={s.contacts}>
              <li><Phone aria-hidden />{u.phone ? <a href={`tel:${u.phone}`} className="a mono">{u.phone}</a> : "—"}</li>
              <li><Send aria-hidden />{u.telegram ? <Badge tone="success" dot>Telegram ulangan</Badge> : <Badge tone="warning" dot>Telegram ulanmagan</Badge>}</li>
            </ul>
          </Card>
          {isStudent && (
            <Card>
              <CardHeader title="A'zolik tarixi" as="h3" />
              <div className={s.pad}><Timeline rows={data.timeline} labels={enrollmentLabels(st)} showGroup /></div>
            </Card>
          )}
          {canLeads && data.leads.length > 0 && (
            <Card>
              <CardHeader title="Arizalar" as="h3" />
              <ul className={s.people}>
                {data.leads.map((l) => (
                  <li key={l.id}><Inbox aria-hidden className="subtle" /><Link href={`/dashboard/leads?id=${l.id}`} className="a">#{l.id}</Link><span className="subtle">{fmtDay(l.created_at.slice(0, 10))}</span><Badge>{leadLabels[l.status] ?? l.status}</Badge></li>
                ))}
              </ul>
            </Card>
          )}
        </aside>
        <div className={s.main}>
          {data.overview ? <StudentOverview data={data.overview} billingHref={canBilling ? `/dashboard/billing/${u.id}` : undefined} />
            : <Card><EmptyState icon={<UserRound />} title={roles[u.role] ?? u.role}>O&apos;quv ma&apos;lumotlari faqat o&apos;quvchilar uchun ko&apos;rsatiladi.</EmptyState></Card>}
        </div>
      </div>
    </>
  );
}
