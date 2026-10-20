"use client";

import { useTg } from "../_lib/TgProvider";
import type { Group } from "../_lib/data";
import { Async, Empty, Page, Row, Section, useApi } from "../_ui/ui";
import { UpcomingSessions, teachingGroups } from "../_ui/sessions";

/** Guruhlar haftalik jadvali va yaqin 14 kunlik darslar. */
export default function TgSchedule() {
  const { me, t, can } = useTg();
  const groups = useApi<{ data: Group[] }>("groups");
  const weekdays = t("weekdays").split(",");
  return (
    <Page title={t("nav_schedule")}>
      <Async q={groups}>
        {(d) => {
          const active = can("attendance.mark") ? teachingGroups(d.data, me.id, can("group.manage")) : d.data.filter((g) => g.active);
          if (active.length === 0) return <Empty text={t("courses_empty")} icon="🗓" />;
          return (
            <>
              <Section title={t("weekly")}>
                {active.map((g) => (
                  <Row key={g.id} icon="👥" title={g.name}
                    subtitle={g.schedules.map((s) => `${weekdays[s.weekday - 1] ?? s.weekday} ${s.start_time.slice(0, 5)}–${s.end_time.slice(0, 5)}`).join(" · ") || g.course?.title}
                    right={g.room ?? undefined} />
                ))}
              </Section>
              <UpcomingSessions groups={active} days={14} linkSessions={can("attendance.mark")} />
            </>
          );
        }}
      </Async>
    </Page>
  );
}
