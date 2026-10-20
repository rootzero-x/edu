"use client";

import { useTg } from "../_lib/TgProvider";
import { personName } from "../_lib/format";
import { Async, Badge, Empty, Page, Row, Section, useApi } from "../_ui/ui";
import { billingLabels, fmtYmd, type BillingMe } from "@/lib/billing";

/** Balans (BillingController::me): o'quvchining o'zi. */
export default function TgBalance() {
  const { t, settings } = useTg();
  const L = billingLabels(settings);
  const q = useApi<BillingMe>("billing/me");
  return (
    <Page title={t("balance_title")}>
      <Async q={q}>
        {(d) => !d.enabled || d.students.length === 0 ? <Empty text={t("balance_empty")} icon="💰" /> : (
          <>
            {d.students.map((x) => (
              <div key={x.student.id}>
                <Section title={d.students.length > 1 ? personName(x.student) : t("balance")}>
                  <div className="tg-balance">
                    <b className={x.balance < 0 ? "bad" : x.balance > 0 ? "ok" : ""}>{L.money(x.balance)}</b>
                    {x.debt > 0 ? <Badge tone="bad">{t("balance_debt", { debt: L.money(x.debt) })}</Badge> : <Badge tone="ok">{t("balance_ok")}</Badge>}
                    <small>{t("balance_month", { amount: L.money(x.month_charged) })}</small>
                    {x.last_payment && <small>{t("balance_last_payment", { amount: L.money(x.last_payment.amount), date: fmtYmd(x.last_payment.date) })}</small>}
                  </div>
                </Section>
                {x.recent.length > 0 && (
                  <Section title={t("balance_recent")}>
                    {x.recent.map((e) => (
                      <Row key={e.id} icon={e.type === "PAYMENT" ? "💵" : "🧾"}
                        title={<span style={e.voided ? { textDecoration: "line-through", opacity: 0.6 } : undefined}>{L.signed(e.amount)}</span>}
                        subtitle={[fmtYmd(e.entry_date), L.types[e.type] ?? e.type, e.method && (L.methods[e.method] ?? e.method), e.voided && t("balance_voided")].filter(Boolean).join(" · ")} />
                    ))}
                  </Section>
                )}
              </div>
            ))}
          </>
        )}
      </Async>
    </Page>
  );
}
