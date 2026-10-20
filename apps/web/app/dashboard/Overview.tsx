"use client";

import Link from "next/link";
import { useCallback, useState, type ReactNode } from "react";
import { AlertTriangle, BookOpen, CalendarClock, CalendarX2, CheckCircle2, ClipboardCheck, GraduationCap, PieChart, UsersRound } from "lucide-react";
import { Badge, ButtonLink, Card, CardBody, CardHeader, EmptyState, PageHeader, StatCard, cx } from "@/components/ui";
import LiveStatus from "@/components/LiveStatus";
import { useSiteSettings } from "@/components/SiteProvider";
import { call } from "@/lib/client";
import { fmtLongDate, fmtTime } from "@/lib/format";
import { refreshSeconds, statusLabels, timezone } from "@/lib/settings";
import { STATUS_COLOR } from "@/lib/status";
import type { Summary } from "@/lib/types";
import { usePolling } from "@/lib/usePolling";
import SetupChecklist from "./SetupChecklist";
import s from "./overview.module.css";

/** children — sarlavhadan keyingi qo'shimcha bloklar (masalan, o'quvchi/ota-ona uchun "Balans"). */
export default function Overview({ initial, firstName, children }: { initial: Summary | null; firstName: string; children?: ReactNode }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const labels = statusLabels(st);
  const seconds = refreshSeconds(st);
  const [data, setData] = useState<Summary | null>(initial);
  const [loadedAt] = useState(() => (initial ? Date.now() : null));

  const fetcher = useCallback(async (signal: AbortSignal) => {
    const r = await call<Summary>("GET", "dashboard/summary", undefined, { signal });
    return r.ok ? r.data : null;
  }, []);
  const live = usePolling(fetcher, setData, { seconds, immediate: !initial });

  const today = fmtLongDate(data?.generated_at ?? Date.now(), tz);
  const header = (
    <PageHeader
      title={firstName ? `Salom, ${firstName}!` : "Bosh sahifa"}
      description={today}
      actions={<LiveStatus {...live} lastUpdated={live.lastUpdated ?? loadedAt} onRefresh={live.refresh} seconds={seconds} />}
    />
  );

  if (!data) {
    return (
      <>
        {header}
        {children}
        <Card><EmptyState icon={<AlertTriangle />} title="Ma'lumotni yuklab bo'lmadi">{seconds > 0 ? "API bilan aloqa yo'q. Birozdan keyin avtomatik qayta urinadi." : "API bilan aloqa yo'q. Yuqoridagi yangilash tugmasini bosing."}</EmptyState></Card>
      </>
    );
  }

  const { stats } = data;
  const now = Date.now();
  const totalMarks = Object.values(stats.attendance_counts).reduce((a, b) => a + b, 0);
  const rate = stats.attendance_rate;

  return (
    <>
      {header}
      {data.setup && <SetupChecklist setup={data.setup} />}
      {children}
      <section className={s.stats} aria-label="Statistika">
        <StatCard
          label="Bugungi mashg'ulotlar"
          value={stats.today_sessions}
          icon={<CalendarClock />}
          hint={stats.today_sessions ? `${stats.today_marked} tasida davomat belgilangan` : "Bugun mashg'ulot yo'q"}
          progress={stats.today_sessions ? (stats.today_marked / stats.today_sessions) * 100 : null}
        />
        <StatCard
          label="Davomat"
          value={rate == null ? "—" : `${rate}%`}
          icon={<PieChart />}
          tone={rate == null ? "accent" : rate >= 85 ? "success" : rate >= 65 ? "warning" : "danger"}
          progress={rate}
          hint={`So'nggi ${stats.attendance_days} kun`}
        />
        {stats.courses != null && <StatCard label="Kurslar" value={stats.courses} icon={<BookOpen />} tone="info" />}
        <StatCard label="Guruhlar" value={stats.groups} icon={<UsersRound />} tone="info" />
        {stats.students != null && <StatCard label="O'quvchilar" value={stats.students} icon={<GraduationCap />} tone="success" />}
        {stats.unmarked_sessions != null && (
          <StatCard
            label="Belgilanmagan mashg'ulotlar"
            value={stats.unmarked_sessions}
            icon={stats.unmarked_sessions ? <AlertTriangle /> : <CheckCircle2 />}
            tone={stats.unmarked_sessions ? "warning" : "success"}
            hint={stats.unmarked_sessions ? "Davomatni belgilashni unutmang" : "Hammasi belgilangan"}
          />
        )}
      </section>

      <div className={s.cols}>
        <Card>
          <CardHeader title="Bugungi jadval" description={`${data.today.length} ta mashg'ulot`} actions={<ButtonLink href="/dashboard/groups" variant="ghost" size="sm">Guruhlar</ButtonLink>} />
          {data.today.length === 0 ? (
            <EmptyState compact icon={<CalendarX2 />} title="Bugun mashg'ulot yo'q">Jadvaldagi mashg&apos;ulotlar shu yerda ko&apos;rinadi.</EmptyState>
          ) : (
            <ul className={s.sessions}>
              {data.today.map((x) => {
                const start = new Date(x.starts_at).getTime();
                const end = new Date(x.ends_at).getTime();
                const running = !x.cancelled && now >= start && now <= end;
                const done = x.students_count > 0 && x.marked_count >= x.students_count;
                return (
                  <li key={x.id} className={cx(s.session, running && s.now)}>
                    <div className={s.time}><b>{fmtTime(x.starts_at, tz)}</b><span>{fmtTime(x.ends_at, tz)}</span></div>
                    <div className={s.info}>
                      <strong><Link href={`/dashboard/groups/${x.group_id}`} className="a">{x.group_name}</Link></strong>
                      <div className={s.meta}>{[x.course_title, x.teacher, x.room, x.topic].filter(Boolean).join(" · ")}</div>
                    </div>
                    <div className={s.side}>
                      {x.cancelled ? <Badge>Bekor qilingan</Badge>
                        : running ? <Badge tone="accent" dot>Davom etmoqda</Badge>
                        : now < start ? <Badge tone="info">Kutilmoqda</Badge>
                        : done ? <Badge tone="success" icon={<CheckCircle2 />}>{x.marked_count}/{x.students_count}</Badge>
                        : <Badge tone="warning">{x.marked_count}/{x.students_count}</Badge>}
                      {!x.cancelled && (x.can_mark || now >= start) && (
                        <ButtonLink href={`/dashboard/sessions/${x.id}`} size="sm" variant={x.can_mark && !done ? "primary" : "secondary"} icon={<ClipboardCheck />}>
                          Davomat
                        </ButtonLink>
                      )}
                    </div>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>

        <Card>
          <CardHeader title="Davomat taqsimoti" description={`So'nggi ${stats.attendance_days} kun · ${totalMarks} ta belgi`} />
          <CardBody>
            {totalMarks === 0 ? (
              <p className="muted" style={{ margin: 0 }}>Hali belgilar yo&apos;q.</p>
            ) : (
              <>
                <div className={s.bar} role="img" aria-label={Object.entries(stats.attendance_counts).map(([k, v]) => `${labels[k] ?? k}: ${Math.round((v / totalMarks) * 100)}%`).join(", ")}>
                  {Object.entries(stats.attendance_counts).map(([k, v]) => (
                    <span key={k} style={{ width: `${(v / totalMarks) * 100}%`, background: STATUS_COLOR[k] ?? "var(--fg-subtle)" }} />
                  ))}
                </div>
                <div className={s.legend}>
                  {Object.entries(stats.attendance_counts).map(([k, v]) => (
                    <div className={s.legendRow} key={k}>
                      <span><i className={s.sw} style={{ background: STATUS_COLOR[k] ?? "var(--fg-subtle)" }} aria-hidden />{labels[k] ?? k}</span>
                      <span>{v} <span className="subtle" style={{ fontWeight: 400 }}>({Math.round((v / totalMarks) * 100)}%)</span></span>
                    </div>
                  ))}
                </div>
              </>
            )}
          </CardBody>
        </Card>
      </div>
    </>
  );
}
