"use client";

import { useTg } from "../_lib/TgProvider";
import type { Group } from "../_lib/data";
import { Async, Empty, Page, useApi } from "../_ui/ui";
import { TodaySessions, UnmarkedSessions, UpcomingSessions, teachingGroups } from "../_ui/sessions";

export default function TgToday() {
  const { me, t, can } = useTg();
  const groups = useApi<{ data: Group[] }>("groups");
  if (!can("attendance.mark")) return <Page><Empty text={t("no_access")} icon="🔒" /></Page>;
  return (
    <Page title={t("today_title")}>
      <Async q={groups}>
        {(d) => {
          const mine = teachingGroups(d.data, me.id, can("group.manage"));
          return (
            <>
              <TodaySessions groups={mine} />
              <UnmarkedSessions />
              <UpcomingSessions groups={mine} days={7} linkSessions fromTomorrow />
            </>
          );
        }}
      </Async>
    </Page>
  );
}
