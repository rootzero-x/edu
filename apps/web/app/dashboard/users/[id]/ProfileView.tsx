"use client";

import Link from "next/link";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { Inbox, Link2, Phone, Send, UserRound, Users as UsersIcon } from "lucide-react";
import { Avatar, Badge, Button, Card, CardHeader, Dialog, EmptyState, PageHeader, useToast } from "@/components/ui";
import ChildOverview from "@/components/family/ChildOverview";
import PersonPicker from "@/components/PersonPicker";
import { useSiteSettings } from "@/components/SiteProvider";
import { ROLE_LABELS } from "@/components/shell/nav";
import { call, errorText } from "@/lib/client";
import { enrollmentLabels, fmtDay, type TimelineRow } from "@/lib/enrollment";
import type { ChildOverview as Overview } from "@/lib/family";
import { fmtDate, personName } from "@/lib/format";
import { obj, timezone } from "@/lib/settings";
import { Timeline } from "../../groups/[id]/Members";
import s from "./profile.module.css";

type Contact = { id: number; first_name: string; last_name: string | null; phone: string | null; telegram?: boolean };
export type ProfileData = {
  user: { id: number; phone: string; first_name: string; last_name: string | null; role: string; active: boolean; created_at: string | null; telegram: boolean };
  can_manage: boolean;
  parents: Contact[];
  children: Contact[];
  overview: Overview | null;
  timeline: TimelineRow[];
  leads: { id: number; status: string; source: string; created_at: string }[];
};

/** O'quvchi (yoki boshqa foydalanuvchi) profili: kontaktlar, ota-onalar, a'zolik tarixi, davomat, natijalar, to'lov, arizalar. */
export default function ProfileView({ data, canBilling, canUsers, canLeads }: { data: ProfileData; canBilling: boolean; canUsers: boolean; canLeads: boolean }) {
  const st = useSiteSettings();
  const router = useRouter();
  const toast = useToast();
  const tz = timezone(st);
  const roles = { ...ROLE_LABELS, ...obj<Record<string, string>>(st, "telegram.role_labels") };
  const leadLabels = obj<Record<string, string>>(st, "leads.status_labels");
  const [linking, setLinking] = useState(false);
  const u = data.user;
  const isStudent = u.role === "STUDENT";

  async function link(parentId: number) {
    const r = await call("POST", `users/${u.id}/parents`, { parent_id: parentId });
    if (!r.ok) return toast.error("Bog'lanmadi", errorText(r.data));
    toast.success("Ota-ona bog'landi");
    setLinking(false);
    router.refresh();
  }

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
              <CardHeader title="Ota-onalar" as="h3" actions={canUsers && <Button size="sm" variant="ghost" icon={<Link2 />} onClick={() => setLinking(true)}>Bog&apos;lash</Button>} />
              {data.parents.length === 0 ? <EmptyState compact icon={<UsersIcon />} title="Bog'lanmagan">Ota-ona davomat va to&apos;lov xabarlarini Telegram orqali oladi.</EmptyState> : (
                <ul className={s.people}>
                  {data.parents.map((p) => (
                    <li key={p.id}>
                      <Avatar name={personName(p)} size={30} />
                      <span><Link href={`/dashboard/users/${p.id}`} className="a"><b>{personName(p)}</b></Link>{p.phone && <span className="subtle mono">{p.phone}</span>}</span>
                      {p.telegram === false && <Badge tone="warning">TG yo&apos;q</Badge>}
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}
          {u.role === "PARENT" && (
            <Card>
              <CardHeader title="Farzandlari" as="h3" />
              {data.children.length === 0 ? <EmptyState compact icon={<UsersIcon />} title="Bog'lanmagan" /> : (
                <ul className={s.people}>
                  {data.children.map((c) => (
                    <li key={c.id}><Avatar name={personName(c)} size={30} /><span><Link href={`/dashboard/users/${c.id}`} className="a"><b>{personName(c)}</b></Link><span className="subtle mono">{c.phone}</span></span></li>
                  ))}
                </ul>
              )}
            </Card>
          )}
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
          {data.overview ? <ChildOverview data={data.overview} billingHref={canBilling ? `/dashboard/billing/${u.id}` : undefined} />
            : <Card><EmptyState icon={<UserRound />} title={roles[u.role] ?? u.role}>O&apos;quv ma&apos;lumotlari faqat o&apos;quvchilar uchun ko&apos;rsatiladi.</EmptyState></Card>}
        </div>
      </div>
      <Dialog open={linking} onClose={() => setLinking(false)} title="Ota-onani bog'lash" description="Yangi ota-onani «Foydalanuvchilar» bo'limida yarating" icon={<Link2 />} width={520}>
        {linking && <PersonPicker role="PARENT" label="Ota-onani qidirish" exclude={new Set(data.parents.map((p) => p.id))} onPick={(p) => link(p.id)} />}
      </Dialog>
    </>
  );
}
