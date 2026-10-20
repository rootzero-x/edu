"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { CheckCircle2, ChevronRight, Circle, Rocket, X } from "lucide-react";
import { Button, Card, Progress, useConfirm, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { obj } from "@/lib/settings";
import { call, errorText } from "@/lib/client";
import { fmtMoney } from "@/lib/format";
import type { Summary } from "@/lib/types";
import s from "./setup.module.css";

const KEY = "tisu.setup.dismissed";
const FALLBACK: Record<string, string> = {
  title: "Platformani sozlashni yakunlang", text: "Har bir band haqiqiy ma'lumotdan belgilanadi.", dismiss: "Yashirish",
  site: "Markaz nomi va aloqa ma'lumotlari", bot: "Telegram botni ulang", telegram: "O'z Telegramingizni ulang", billing: "Kurs narxini tasdiqlang",
  confirm: "Tasdiqlash", confirm_title: "Oylik narxni tasdiqlaysizmi?",
  confirm_text: "Narx o'zgarmaydi, faqat tasdiqlangan deb belgilanadi. Boshqa narx kerak bo'lsa — sozlamalarda o'zgartiring.",
  course: "Birinchi kursni yarating", teacher: "O'qituvchi qo'shing", group: "Guruh oching", schedule: "Guruhga dars jadvalini kiriting", students: "O'quvchilarni qo'shing",
};

/** Yangi admin uchun "Boshlash" ro'yxati: hammasi bajarilgach yoki yashirilgach ko'rinmaydi. */
export default function SetupChecklist({ setup, onChanged }: { setup: NonNullable<Summary["setup"]>; onChanged?: () => void }) {
  const t = { ...FALLBACK, ...obj<Record<string, string>>(useSiteSettings(), "ui.setup_labels") };
  const [hidden, setHidden] = useState(true);
  const [busy, setBusy] = useState(false);
  const confirm = useConfirm();
  const toast = useToast();
  useEffect(() => {
    try { setHidden(localStorage.getItem(KEY) === "1"); } catch { setHidden(false); }
  }, []);
  if (hidden || setup.done >= setup.total) return null;
  const next = setup.steps.find((x) => !x.done);
  /** Standart narx to'g'ri bo'lsa ham sozlamalar formasi o'zgarmagan qiymatni saqlamaydi — shu yerdan tasdiqlanadi. */
  async function confirmFee() {
    setBusy(true);
    const r = await call<{ data: { key: string; value: unknown }[] }>("GET", "settings");
    const get = (k: string) => r.data.data?.find((i) => i.key === k)?.value;
    const fee = Number(get("billing.monthly_fee"));
    if (!r.ok || !Number.isFinite(fee)) { setBusy(false); return toast.error(errorText(r.data)); }
    const amount = fmtMoney(fee, String(get("billing.currency") ?? ""));
    if (!(await confirm({ title: t.confirm_title, description: <><strong>{amount}</strong><br />{t.confirm_text}</>, confirmText: t.confirm }))) return setBusy(false);
    const p = await call("PATCH", "settings", { values: { "billing.monthly_fee": fee } });
    setBusy(false);
    if (!p.ok) return toast.error(errorText(p.data));
    toast.success(t.billing, amount);
    onChanged?.();
  }

  const dismiss = () => { try { localStorage.setItem(KEY, "1"); } catch { /* ignore */ } setHidden(true); };

  return (
    <Card className={s.card} as="section" aria-labelledby="setup-title">
      <div className={s.head}>
        <span className={s.icon} aria-hidden><Rocket /></span>
        <div className={s.headText}>
          <h2 id="setup-title">{t.title}</h2>
          <p>{t.text}</p>
        </div>
        <Button size="sm" variant="ghost" iconOnly icon={<X />} aria-label={t.dismiss} title={t.dismiss} onClick={dismiss} />
      </div>
      <div className={s.progress}><Progress value={(setup.done / setup.total) * 100} label="Bajarilgan" /><b>{setup.done}/{setup.total}</b></div>
      <ol className={s.steps}>
        {setup.steps.map((x) => (
          <li key={x.key} data-done={x.done || undefined} data-next={x === next || undefined}>
            <Link href={x.href}>
              {x.done ? <CheckCircle2 aria-label="Bajarildi" className={s.ok} /> : <Circle aria-label="Bajarilmagan" />}
              <span>{t[x.key] ?? x.key}</span>
              {!x.done && <ChevronRight aria-hidden className={s.chev} />}
            </Link>
            {x.key === "billing" && !x.done && (
              <Button size="sm" variant="soft" className={s.inlineAction} loading={busy} onClick={confirmFee}>{t.confirm}</Button>
            )}
          </li>
        ))}
      </ol>
    </Card>
  );
}
