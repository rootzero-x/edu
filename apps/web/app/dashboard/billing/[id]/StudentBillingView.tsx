"use client";

import { useState, useTransition } from "react";
import { usePathname, useRouter } from "next/navigation";
import { Ban, Banknote, CalendarRange, ChevronLeft, ChevronRight, HandCoins, Receipt, Scale, TrendingDown, UsersRound, Wallet } from "lucide-react";
import { Badge, Button, Card, CardHeader, EmptyState, PageHeader, StatCard, Table, Tabs, Td, Th, cx, usePrompt, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import EntryDialog from "@/components/billing/EntryDialog";
import { billingLabels, fmtYmd, monthLabel, type BillingEntry, type StudentBilling } from "@/lib/billing";
import { call, errorText } from "@/lib/client";
import { personName } from "@/lib/format";
import s from "../billing.module.css";

const TYPE_TABS = [
  { id: "", label: "Barchasi" }, { id: "MANUAL", label: "To'lov va tuzatishlar" }, { id: "CHARGE", label: "Kunlik hisoblash" },
];

export default function StudentBillingView({ data, type }: { data: StudentBilling; type: string }) {
  const L = billingLabels(useSiteSettings());
  const router = useRouter();
  const path = usePathname();
  const toast = useToast();
  const prompt = usePrompt();
  const [dialog, setDialog] = useState<"payment" | "adjustment" | null>(null);
  const [loading, startTransition] = useTransition();
  const name = personName(data.student);
  const manage = data.can_manage;

  const go = (params: { type?: string; page?: string }) => {
    const sp = new URLSearchParams({ type, ...params });
    for (const [k, v] of [...sp.entries()]) if (!v) sp.delete(k);
    startTransition(() => router.replace(`${path}${sp.size ? `?${sp}` : ""}`, { scroll: false }));
  };

  const voidEntry = (e: BillingEntry) => prompt({
    title: "Yozuvni bekor qilish",
    description: `${L.types[e.type] ?? e.type}: ${L.signed(e.amount)} · ${fmtYmd(e.entry_date)}. Yozuv o'chirilmaydi — "bekor qilingan" deb belgilanadi va balansdan chiqariladi.`,
    fields: [{ name: "reason", label: "Sabab", type: "textarea", required: true, placeholder: "Masalan: summa noto'g'ri kiritilgan" }],
    submitText: "Bekor qilish",
    onSubmit: async (v) => {
      if ((v.reason ?? "").trim().length < 3) return "Sababni yozing (kamida 3 belgi).";
      const r = await call<{ balance: number }>("POST", `billing/entries/${e.id}/void`, { reason: v.reason.trim() });
      if (!r.ok) return errorText(r.data);
      toast.success("Yozuv bekor qilindi", `Yangi balans: ${L.money(r.data.balance)}`);
      router.refresh();
    },
  });

  return (
    <>
      <PageHeader
        title={name}
        description={`${data.student.phone} · Oylik narx: ${L.money(data.monthly_fee)}`}
        back={manage ? { href: "/dashboard/billing", label: "To'lovlar" } : { href: "/dashboard", label: "Bosh sahifa" }}
        actions={manage && (
          <>
            <Button variant="secondary" icon={<Scale />} onClick={() => setDialog("adjustment")}>Tuzatish</Button>
            <Button icon={<HandCoins />} onClick={() => setDialog("payment")}>To&apos;lov qabul qilish</Button>
          </>
        )}
      />

      <section className={s.stats} aria-label="Balans">
        <StatCard label="Balans" value={L.money(data.balance)} icon={<Wallet />} tone={data.balance < 0 ? "danger" : "success"}
          hint={data.debt > 0 ? `Qarzdorlik: ${L.money(data.debt)}` : "Qarzdorlik yo'q"} />
        <StatCard label="Shu oy hisoblandi" value={L.money(data.month_charged)} icon={<Receipt />} tone="info" />
        <StatCard label="Jami to'langan" value={L.money(data.totals.paid)} icon={<Banknote />} tone="success"
          hint={data.last_payment ? `Oxirgi: ${L.money(data.last_payment.amount)} · ${fmtYmd(data.last_payment.date)}` : "Hali to'lov yo'q"} />
        <StatCard label="Jami hisoblangan" value={L.money(data.totals.charged)} icon={<TrendingDown />} tone="accent"
          hint={data.totals.adjusted ? `Tuzatishlar: ${L.signed(data.totals.adjusted)}` : undefined} />
      </section>

      <div className={s.cols}>
        <Card>
          <CardHeader title="Guruhlar" description="Har bir guruh (kurs) uchun kunlik hisoblanadi" />
          {data.groups.length === 0 ? <EmptyState compact icon={<UsersRound />} title="Guruhga a'zo emas">Hisoblash to&apos;xtagan.</EmptyState> : (
            <Table stack caption="Guruhlar">
              <thead><tr><Th>Guruh</Th><Th>Qo&apos;shilgan</Th><Th numeric>Bugun</Th></tr></thead>
              <tbody>
                {data.groups.map((g) => (
                  <tr key={g.id}>
                    <Td>{g.name} {!g.active && <Badge>Faol emas</Badge>}</Td>
                    <Td label="Qo'shilgan">{fmtYmd(g.joined_at)}</Td>
                    <Td label="Bugun" numeric><span className={s.money}>{L.money(g.daily_charge)}</span></Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
        <Card>
          <CardHeader title="Oylar bo'yicha" description="So'nggi 12 oy" />
          {data.months.length === 0 ? <EmptyState compact icon={<CalendarRange />} title="Hali yozuv yo'q" /> : (
            <Table stack caption="Oylar bo'yicha">
              <thead><tr><Th>Oy</Th><Th numeric>Hisoblandi</Th><Th numeric>To&apos;landi</Th></tr></thead>
              <tbody>
                {data.months.map((m) => (
                  <tr key={m.month}>
                    <Td>{monthLabel(m.month)}</Td>
                    <Td label="Hisoblandi" numeric><span className={s.money}>{L.money(m.charged)}</span></Td>
                    <Td label="To'landi" numeric>
                      <span className={s.money}>{L.money(m.paid)}</span>
                      {m.adjusted !== 0 && <span className={s.reason}>Tuzatish: {L.signed(m.adjusted)}</span>}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </Card>
      </div>

      <Card style={{ opacity: loading ? 0.6 : 1, transition: "opacity 150ms" }} aria-busy={loading}>
        <CardHeader title="Tarix" description={`${data.meta.total} ta yozuv`} />
        <div style={{ padding: "0 16px 12px" }}>
          <Tabs variant="pills" label="Yozuv turi" value={type} onChange={(id) => go({ type: id, page: "" })} items={TYPE_TABS} />
        </div>
        {data.entries.length === 0 ? <EmptyState compact icon={<Receipt />} title="Yozuv yo'q" /> : (
          <Table stack caption="To'lovlar tarixi">
            <thead><tr><Th>Sana</Th><Th>Tur</Th><Th numeric>Summa</Th><Th>Tafsilot</Th>{manage && <Th actions><span className="sr-only">Amallar</span></Th>}</tr></thead>
            <tbody>
              {data.entries.map((e) => (
                <tr key={e.id}>
                  <Td>{fmtYmd(e.entry_date)}</Td>
                  <Td label="Tur">
                    <Badge tone={e.type === "PAYMENT" ? "success" : e.type === "ADJUSTMENT" ? "accent" : "neutral"}>{L.types[e.type] ?? e.type}</Badge>
                    {e.voided && <> <Badge tone="danger">Bekor qilingan</Badge></>}
                  </Td>
                  <Td label="Summa" numeric>
                    <span className={cx(s.money, e.voided ? s.voided : e.amount < 0 ? s.neg : s.pos)}>{L.signed(e.amount)}</span>
                  </Td>
                  <Td label="Tafsilot">
                    {[e.method && (L.methods[e.method] ?? e.method), e.group, e.note].filter(Boolean).join(" · ") || <span className="subtle">—</span>}
                    {e.voided && e.void_reason && <span className={s.reason}>Sabab: {e.void_reason}</span>}
                  </Td>
                  {manage && (
                    <Td actions>
                      {e.type !== "CHARGE" && !e.voided && (
                        <Button size="sm" variant="ghost" icon={<Ban />} onClick={() => voidEntry(e)}>Bekor qilish</Button>
                      )}
                    </Td>
                  )}
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {data.meta.pages > 1 && (
        <nav className="row" style={{ justifyContent: "center", marginTop: 16 }} aria-label="Sahifalar">
          <Button variant="secondary" size="sm" icon={<ChevronLeft />} disabled={data.meta.page <= 1} onClick={() => go({ page: String(data.meta.page - 1) })}>Oldingi</Button>
          <span className="muted" aria-current="page">{data.meta.page} / {data.meta.pages}</span>
          <Button variant="secondary" size="sm" disabled={data.meta.page >= data.meta.pages} onClick={() => go({ page: String(data.meta.page + 1) })}>Keyingi<ChevronRight size={16} aria-hidden /></Button>
        </nav>
      )}

      {manage && (
        <EntryDialog
          mode={dialog ?? "payment"}
          open={dialog !== null}
          onClose={() => setDialog(null)}
          student={{ id: data.student.id, name }}
          balance={data.balance}
          onDone={() => router.refresh()}
        />
      )}
    </>
  );
}
