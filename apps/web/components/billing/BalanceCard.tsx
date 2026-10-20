"use client";

import { Wallet } from "lucide-react";
import { Badge, ButtonLink, Card, CardBody, CardHeader, cx } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { billingLabels, fmtYmd, type BillingMe } from "@/lib/billing";
import { personName } from "@/lib/format";
import s from "./balance.module.css";

/** O'quvchining "Balans" kartochkasi. */
export default function BalanceCard({ data }: { data: BillingMe | null }) {
  const L = billingLabels(useSiteSettings());
  if (!data?.enabled || data.students.length === 0) return null;
  const many = data.students.length > 1;
  return (
    <Card style={{ marginBottom: 16 }}>
      <CardHeader title="Balans" description="To'lovlar va qarzdorlik" />
      <CardBody>
        <div className={s.list}>
          {data.students.map((x) => (
            <div key={x.student.id} className={s.item}>
              {many && <span className={s.name}>{personName(x.student)}</span>}
              <span className={cx(s.amount, x.balance < 0 && s.debt, x.balance > 0 && s.credit)}>{L.money(x.balance)}</span>
              {x.debt > 0 ? <Badge tone="danger" dot>Qarzdorlik: {L.money(x.debt)}</Badge> : <Badge tone="success" dot>Qarzdorlik yo&apos;q</Badge>}
              <div className={s.meta}>
                <span>Shu oy hisoblandi: {L.money(x.month_charged)}</span>
                {x.last_payment && <span>Oxirgi to&apos;lov: {L.money(x.last_payment.amount)} · {fmtYmd(x.last_payment.date)}</span>}
              </div>
              <div className={s.foot}>
                <ButtonLink href={`/dashboard/billing/${x.student.id}`} size="sm" variant="secondary" icon={<Wallet />}>Batafsil</ButtonLink>
              </div>
            </div>
          ))}
        </div>
      </CardBody>
    </Card>
  );
}
