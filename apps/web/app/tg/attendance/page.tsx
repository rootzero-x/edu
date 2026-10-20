"use client";

import { Fragment, useEffect, useState } from "react";
import { tg } from "../_lib/api";
import { useTg } from "../_lib/TgProvider";
import type { Group } from "../_lib/data";
import { pct } from "../_lib/format";
import { Async, Badge, Empty, Failed, Loading, Page, Progress, Row, Section, useApi } from "../_ui/ui";
import { teachingGroups } from "../_ui/sessions";

type ReportRow = { student_id: number; name: string; counts: Record<string, number>; rate: number; left?: boolean };

const marked = (r: ReportRow) => Object.values(r.counts).reduce((a, b) => a + b, 0) > 0;

/** Guruh o'rtacha davomati — belgisi bor o'quvchilar bo'yicha (hali dars bo'lmagan guruh — null). */
function groupRate(rows: ReportRow[]): number | null {
  const m = rows.filter(marked);
  return m.length ? m.reduce((a, r) => a + r.rate, 0) / m.length : null;
}

function StudentCard({ r, title }: { r: ReportRow; title: string }) {
  const { t, settings } = useTg();
  const emoji = (settings["telegram.attendance_emoji"] ?? {}) as Record<string, string>;
  const labels = (settings["attendance.status_labels"] ?? {}) as Record<string, string>;
  return (
    <div className="tg-student">
      <div className="name">
        <span>{title}{r.left ? ` · ${t("left_group")}` : ""}</span>
        <b>{pct(r.rate)}</b>
      </div>
      <Progress value={r.rate} />
      <div className="tg-stats" style={{ padding: "10px 0 0" }}>
        {(Object.keys(labels).length ? Object.keys(labels) : ["PRESENT", "LATE", "ABSENT", "EXCUSED"]).map((s) => (
          <div key={s}><b>{emoji[s]} {r.counts[s] ?? 0}</b><small>{labels[s] ?? s}</small></div>
        ))}
      </div>
    </div>
  );
}

/**
 * Davomat (GET /attendance/reports — barcha guruhlar bitta so'rovda): o'quvchi — o'zi (har guruh bo'yicha karta);
 * o'qituvchi/admin — guruhlar ro'yxati o'rtacha foiz bilan, bosilganda o'quvchilar ochiladi
 * (ko'p guruhli adminda sahifa yuzlab kartaga to'lib ketmasin).
 */
function Reports({ groups }: { groups: Group[] }) {
  const { t, can } = useTg();
  const staff = can("attendance.mark");
  const [data, setData] = useState<{ group: Group; rows: ReportRow[] }[] | null | "error">(null);
  const [open, setOpen] = useState<number | null>(null);
  const ids = groups.map((g) => g.id).join(",");
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    let cancelled = false;
    setData(null);
    const byId = new Map(groups.map((g) => [g.id, g]));
    tg<{ data: { group_id: number; rows: ReportRow[] }[] }>("GET", `attendance/reports?group_ids=${ids}`).then((r) => {
      if (cancelled) return;
      if (!r.ok) return setData("error");
      setData(r.data.data.flatMap((x) => (byId.has(x.group_id) ? [{ group: byId.get(x.group_id)!, rows: x.rows }] : [])));
    });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ids, attempt]);
  if (data === "error") return <Failed onRetry={() => setAttempt((n) => n + 1)} />;
  if (!data) return <Loading />;
  const withRows = data.filter((d) => d.rows.length > 0);
  if (withRows.length === 0) return <Empty text={t("attendance_empty")} icon="✅" />;

  if (!staff) {
    return (
      <>
        {withRows.map(({ group, rows }) => (
          <Section key={group.id} title={`${group.name} · ${group.course?.title ?? ""}`}>
            {rows.map((r) => <StudentCard key={r.student_id} r={r} title={t("attendance_rate")} />)}
          </Section>
        ))}
      </>
    );
  }

  return (
    <Section title={t("attendance_groups", { n: withRows.length })}>
      {withRows.map(({ group, rows }) => {
        const rate = groupRate(rows);
        const isOpen = open === group.id;
        return (
          <Fragment key={group.id}>
            <Row icon="👥" title={group.name}
              subtitle={`${group.course?.title ?? ""} · ${rows.filter((r) => !r.left).length} ${t("students")}`}
              right={rate === null ? "—" : <Badge tone={rate >= 80 ? "ok" : rate >= 60 ? "warn" : "bad"}>{pct(rate)}</Badge>}
              onClick={() => setOpen(isOpen ? null : group.id)} />
            {isOpen && rows.map((r) => <StudentCard key={r.student_id} r={r} title={r.name} />)}
          </Fragment>
        );
      })}
    </Section>
  );
}

export default function TgAttendance() {
  const { t, me, can } = useTg();
  const groups = useApi<{ data: Group[] }>("groups");
  // Xodim — faqat o'zi dars beradigan guruhlar (admin — hammasi), boshqalar — a'zo bo'lgan faol guruhlar.
  const pick = (all: Group[]) => (can("attendance.mark") ? teachingGroups(all, me.id, can("group.manage")) : all.filter((g) => g.active));
  return (
    <Page title={t("nav_attendance")}>
      <Async q={groups}>
        {(d) => pick(d.data).length ? <Reports groups={pick(d.data)} /> : <Empty text={t("attendance_empty")} icon="✅" />}
      </Async>
    </Page>
  );
}
