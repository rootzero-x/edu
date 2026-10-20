"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { CheckCircle2, ChevronRight, Circle, Rocket, X } from "lucide-react";
import { Button, Card, Progress } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { obj } from "@/lib/settings";
import type { Summary } from "@/lib/types";
import s from "./setup.module.css";

const KEY = "tisu.setup.dismissed";
const FALLBACK: Record<string, string> = {
  title: "Platformani sozlashni yakunlang", text: "Har bir band haqiqiy ma'lumotdan belgilanadi.", dismiss: "Yashirish",
  site: "Markaz nomi va aloqa ma'lumotlari", bot: "Telegram botni ulang", telegram: "O'z Telegramingizni ulang", billing: "Kurs narxini tasdiqlang",
  course: "Birinchi kursni yarating", teacher: "O'qituvchi qo'shing", group: "Guruh oching", schedule: "Guruhga dars jadvalini kiriting", students: "O'quvchilarni qo'shing",
};

/** Yangi admin uchun "Boshlash" ro'yxati: hammasi bajarilgach yoki yashirilgach ko'rinmaydi. */
export default function SetupChecklist({ setup }: { setup: NonNullable<Summary["setup"]> }) {
  const t = { ...FALLBACK, ...obj<Record<string, string>>(useSiteSettings(), "ui.setup_labels") };
  const [hidden, setHidden] = useState(true);
  useEffect(() => {
    try { setHidden(localStorage.getItem(KEY) === "1"); } catch { setHidden(false); }
  }, []);
  if (hidden || setup.done >= setup.total) return null;
  const next = setup.steps.find((x) => !x.done);
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
          </li>
        ))}
      </ol>
    </Card>
  );
}
