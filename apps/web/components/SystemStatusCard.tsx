"use client";

import Link from "next/link";
import { useCallback, useState } from "react";
import { Activity, Archive, Send, UserCheck } from "lucide-react";
import { Badge, Card, CardBody, CardHeader, type Tone } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call } from "@/lib/client";
import { fmtDateTime } from "@/lib/format";
import { timezone } from "@/lib/settings";
import { usePolling } from "@/lib/usePolling";

export type SystemStatus = {
  scheduler: { last_tick: string | null; minutes_since: number | null; stale: boolean; stale_after: number };
  jobs: { job: string; every_minutes: number; last_ok_at: string | null; last_error: string | null; overdue: boolean }[];
  backup: { at?: string; ok?: boolean; file?: string; size?: number; encrypted?: boolean; error?: string;
    offsite?: { ok: boolean; skipped?: string; chats?: number; parts?: number; error?: string | null }; encryption_configured: boolean };
  telegram: { pending: number; sent_24h: number; failed_24h: number; blocked_chats: number; linked: number };
  users: { pending: number; no_telegram: number };
  warnings: string[];
};

/** Ogohlantirish kodlari → matn (backend SystemStatusController). */
const WARNINGS: Record<string, string> = {
  cron_never: "Cron hali bir marta ham ishlamagan: serverda «* * * * * php artisan schedule:run» qo'yilganini tekshiring.",
  cron_stale: "Cron to'xtagan: eslatmalar, zaxira va to'lov hisoblari ishlamayapti.",
  job_overdue: "Ba'zi avtomatik vazifalar kechikmoqda (pastdagi ro'yxat).",
  backup_unencrypted: "Zaxira shifrlanmagan va serverdan tashqariga yuborilmayapti: .env ga BACKUP_ENCRYPTION_KEY qo'shing.",
  teacher_course_manage: "O'qituvchilarda «Barcha kurslarni boshqarish» huquqi yoqilgan (eski sozlama). Sozlamalar → Rollar huquqlarida «O'z kurslarini yaratish» ga almashtiring.",
};

function Row({ icon, title, tone, value, children }: { icon: React.ReactNode; title: string; tone: Tone; value: string; children?: React.ReactNode }) {
  return (
    <li style={{ display: "flex", gap: 10, alignItems: "flex-start", padding: "8px 0", borderTop: "1px solid var(--border)" }}>
      <span aria-hidden style={{ color: "var(--fg-subtle)", marginTop: 2 }}>{icon}</span>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ display: "flex", justifyContent: "space-between", gap: 8, flexWrap: "wrap" }}>
          <b style={{ fontWeight: 600 }}>{title}</b><Badge tone={tone} dot>{value}</Badge>
        </div>
        {children && <div className="subtle" style={{ fontSize: 13, marginTop: 2 }}>{children}</div>}
      </div>
    </li>
  );
}

/** Admin bosh sahifasidagi "Tizim holati": cron, zaxira, Telegram yetkazish, arizalar. */
export default function SystemStatusCard({ initial }: { initial: SystemStatus | null }) {
  const tz = timezone(useSiteSettings());
  const [data, setData] = useState(initial);
  const fetcher = useCallback(async (signal: AbortSignal) => {
    const r = await call<SystemStatus>("GET", "system/status", undefined, { signal });
    return r.ok ? r.data : null;
  }, []);
  usePolling(fetcher, setData, { seconds: 120, immediate: !initial });
  if (!data) return null;

  const { scheduler: c, backup: b, telegram: t } = data;
  const overdue = data.jobs.filter((j) => j.overdue);
  const backupTone: Tone = !b.at ? "warning" : !b.ok ? "danger" : b.offsite?.ok ? "success" : "warning";
  return (
    <Card style={{ marginBottom: 16 }}>
      <CardHeader title="Tizim holati" description="Cron, zaxira va Telegram xabarlari" />
      <CardBody>
        {data.warnings.length > 0 && (
          <ul role="alert" style={{ margin: "0 0 8px", paddingLeft: 18, color: "var(--danger, #b91c1c)" }}>
            {data.warnings.map((w) => <li key={w}>{WARNINGS[w] ?? w}</li>)}
          </ul>
        )}
        <ul style={{ listStyle: "none", margin: 0, padding: 0 }}>
          <Row icon={<Activity size={18} />} title="Cron (avtomatik vazifalar)" tone={c.stale ? "danger" : overdue.length ? "warning" : "success"}
            value={!c.last_tick ? "Ishlamagan" : c.stale ? `${c.minutes_since} daqiqa jim` : "Ishlayapti"}>
            {c.last_tick ? `Oxirgi ishga tushish: ${fmtDateTime(c.last_tick, tz)}` : "schedule:run hali chaqirilmagan."}
            {overdue.length > 0 && <> · Kechikmoqda: {overdue.map((j) => j.job.replace("tisu:", "")).join(", ")}</>}
          </Row>
          <Row icon={<Archive size={18} />} title="Zaxira nusxa" tone={backupTone}
            value={!b.at ? "Hali yo'q" : !b.ok ? "Xato" : b.offsite?.ok ? "Serverdan tashqarida" : "Faqat serverda"}>
            {b.at ? `${fmtDateTime(b.at, tz)} · ${b.encrypted ? "shifrlangan" : "shifrlanmagan"}` : "Har kuni 02:30 da olinadi."}
            {b.error && <> · {b.error}</>}
            {b.offsite?.ok && <> · Telegram: {b.offsite.chats} chat, {b.offsite.parts} bo&apos;lak</>}
            {b.offsite && !b.offsite.ok && b.offsite.error && <> · {b.offsite.error}</>}
          </Row>
          <Row icon={<Send size={18} />} title="Telegram xabarlari" tone={t.failed_24h > 0 || t.pending > 200 ? "warning" : "success"}
            value={t.pending ? `Navbatda: ${t.pending}` : "Navbat bo'sh"}>
            24 soatda: yuborildi {t.sent_24h}, xato {t.failed_24h} ·{" "}
            <Link className="a" href="/dashboard/users?view=tg_blocked">botni bloklagan: {t.blocked_chats}</Link> ·{" "}
            <Link className="a" href="/dashboard/users?view=tg_none">ulanmagan: {data.users.no_telegram}</Link>
          </Row>
          <Row icon={<UserCheck size={18} />} title="Bot orqali arizalar" tone={data.users.pending ? "warning" : "success"}
            value={data.users.pending ? `${data.users.pending} ta kutmoqda` : "Yo'q"}>
            {data.users.pending > 0 && <Link className="a" href="/dashboard/users?view=pending">Arizalarni ko&apos;rib chiqish</Link>}
          </Row>
        </ul>
      </CardBody>
    </Card>
  );
}
