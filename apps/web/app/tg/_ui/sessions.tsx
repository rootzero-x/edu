"use client";

import { useTg } from "../_lib/TgProvider";
import { useSessions, type Group } from "../_lib/data";
import { dayKey, isoWeekday, time, todayKey } from "../_lib/format";
import { Badge, Empty, Loading, Failed, Row, Section } from "./ui";

/** O'qituvchi o'zi dars beradigan guruhlar (admin — hammasi). */
export function teachingGroups(groups: Group[], meId: number, manageAll: boolean) {
  return groups.filter((g) => g.active && (manageAll || g.teacher_id === meId));
}

/** Bugungi darslar → davomat varaqasiga havola. */
export function TodaySessions({ groups }: { groups: Group[] }) {
  const { t, tz } = useTg();
  const today = todayKey(tz);
  const q = useSessions(groups, today, today);
  if (q.error) return <Failed onRetry={q.reload} />;
  if (!q.data) return <Loading />;
  const list = q.data.filter((s) => !s.cancelled);
  return (
    <Section title={t("today_title")}>
      {list.length === 0 && <Empty text={t("today_empty")} icon="☕️" />}
      {list.map((s) => (
        <Row key={s.id} href={`/tg/sessions/${s.id}`} icon="📋"
          title={`${time(s.starts_at, tz)} · ${s.group.name}`}
          subtitle={s.topic || s.group.course?.title}
          right={<Badge tone={s.marked_count >= s.group.students_count && s.group.students_count > 0 ? "ok" : "muted"}>{s.marked_count}/{s.group.students_count}</Badge>} />
      ))}
    </Section>
  );
}

/** Yaqin kunlardagi darslar, kunlar bo'yicha. */
export function UpcomingSessions({ groups, days, limit, linkSessions, fromTomorrow }: {
  groups: Group[]; days: number; limit?: number; linkSessions?: boolean; fromTomorrow?: boolean;
}) {
  const { t, tz } = useTg();
  // fromTomorrow: bugungilar alohida ro'yxatda ko'rsatilganda (o'qituvchi "Bugungi darslar") takrorlanmasin.
  const q = useSessions(groups, todayKey(tz, fromTomorrow ? 1 : 0), todayKey(tz, days));
  if (q.error) return <Failed onRetry={q.reload} />;
  if (!q.data) return <Loading />;
  const now = Date.now();
  const list = q.data.filter((s) => new Date(s.ends_at).getTime() > now).slice(0, limit ?? 200);
  if (list.length === 0) return <Section title={t("upcoming")}><Empty text={t("schedule_empty")} icon="🗓" /></Section>;
  const weekdays = t("weekdays").split(",");
  const byDay = new Map<string, typeof list>();
  list.forEach((s) => {
    const k = dayKey(s.starts_at, tz);
    byDay.set(k, [...(byDay.get(k) ?? []), s]);
  });
  const label = (k: string) =>
    k === todayKey(tz) ? t("today") : k === todayKey(tz, 1) ? t("tomorrow") : `${weekdays[isoWeekday(k) - 1] ?? ""}, ${k.slice(8, 10)}.${k.slice(5, 7)}`;
  return (
    <>
      {[...byDay.entries()].map(([k, items]) => (
        <Section key={k} title={label(k)}>
          {items.map((s) => (
            <Row key={s.id} href={linkSessions ? `/tg/sessions/${s.id}` : undefined}
              icon={s.cancelled ? "🚫" : "🕘"}
              title={`${time(s.starts_at, tz)}–${time(s.ends_at, tz)} · ${s.group.name}`}
              subtitle={[s.group.course?.title, s.group.room && `${t("room")}: ${s.group.room}`, s.topic].filter(Boolean).join(" · ")}
              right={s.cancelled ? <Badge tone="bad">{t("cancelled")}</Badge> : undefined} />
          ))}
        </Section>
      ))}
    </>
  );
}
