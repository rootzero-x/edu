"use client";

import { useEffect, useState } from "react";
import { tg } from "../_lib/api";
import { useTg } from "../_lib/TgProvider";
import type { Group } from "../_lib/data";
import { pct } from "../_lib/format";
import { Async, Empty, Failed, Loading, Page, Progress, Section, useApi } from "../_ui/ui";

type ReportRow = { student_id: number; name: string; counts: Record<string, number>; rate: number };

/**
 * Davomat (AttendanceController::report): o'quvchi — o'zi, ota-ona — farzandlari,
 * o'qituvchi/admin — guruhdagi hamma (ko'rinish doirasini API belgilaydi).
 */
function Reports({ groups }: { groups: Group[] }) {
  const { t, settings, me } = useTg();
  const emoji = (settings["telegram.attendance_emoji"] ?? {}) as Record<string, string>;
  const labels = (settings["attendance.status_labels"] ?? {}) as Record<string, string>;
  const [data, setData] = useState<{ group: Group; rows: ReportRow[] }[] | null | "error">(null);
  const ids = groups.map((g) => g.id).join(",");
  useEffect(() => {
    Promise.all(groups.map(async (g) => {
      const r = await tg<{ data: ReportRow[] }>("GET", `groups/${g.id}/attendance`);
      if (!r.ok) throw new Error();
      return { group: g, rows: r.data.data };
    })).then(setData).catch(() => setData("error"));
  }, [ids]);
  if (data === "error") return <Failed />;
  if (!data) return <Loading />;
  const withRows = data.filter((d) => d.rows.length > 0);
  if (withRows.length === 0) return <Empty text={t("attendance_empty")} icon="✅" />;
  const multiStudent = new Set(withRows.flatMap((d) => d.rows.map((r) => r.student_id))).size > 1 || withRows.some((d) => d.rows[0].student_id !== me.id);
  return (
    <>
      {withRows.map(({ group, rows }) => (
        <Section key={group.id} title={`${group.name} · ${group.course?.title ?? ""}`}>
          {rows.map((r) => (
            <div key={r.student_id} className="tg-student">
              <div className="name">
                <span>{multiStudent ? r.name : t("attendance_rate")}</span>
                <b>{pct(r.rate)}</b>
              </div>
              <Progress value={r.rate} />
              <div className="tg-stats" style={{ padding: "10px 0 0" }}>
                {(Object.keys(labels).length ? Object.keys(labels) : ["PRESENT", "LATE", "ABSENT", "EXCUSED"]).map((s) => (
                  <div key={s}><b>{emoji[s]} {r.counts[s] ?? 0}</b><small>{labels[s] ?? s}</small></div>
                ))}
              </div>
            </div>
          ))}
        </Section>
      ))}
    </>
  );
}

export default function TgAttendance() {
  const { t, me } = useTg();
  const groups = useApi<{ data: Group[] }>("groups");
  return (
    <Page title={me.role === "PARENT" ? t("my_children") : t("nav_attendance")}>
      <Async q={groups}>
        {(d) => d.data.length ? <Reports groups={d.data.filter((g) => g.active)} /> : <Empty text={me.role === "PARENT" ? t("children_empty") : t("attendance_empty")} icon="✅" />}
      </Async>
    </Page>
  );
}
