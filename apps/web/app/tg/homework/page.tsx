"use client";

import Link from "next/link";
import { useState } from "react";
import { useTg } from "../_lib/TgProvider";
import { date, time } from "../_lib/format";
import { haptic } from "../_lib/webapp";
import { Async, Badge, Empty, Page, Row, Section, useApi } from "../_ui/ui";
import type { Assignment, HwStatus, MarkRow } from "@/lib/homework";

const TONE: Record<HwStatus, "ok" | "warn" | "bad" | "muted" | "accent"> = { PENDING: "warn", SUBMITTED: "accent", LATE: "warn", GRADED: "ok", MISSED: "bad" };

/** Uyga vazifa: o'quvchiga — vazifalari va baholari, o'qituvchiga — guruhlaridagi vazifalar (topshirganlar soni). */
export default function TgHomework() {
  const { t, settings } = useTg();
  const [tab, setTab] = useState<"open" | "all" | "marks">("open");
  const q = useApi<{ mode: "teacher" | "student"; data: Assignment[] }>(`assignments?status=${tab === "marks" ? "all" : tab}&limit=60`);
  const marks = useApi<{ data: MarkRow[]; average_percent: number | null }>(tab === "marks" ? "marks/me" : null);
  if (settings["homework.enabled"] === false) return <Page><Empty text={t("no_access")} icon="🔒" /></Page>;
  const teacher = q.data?.mode === "teacher";
  return (
    <Page title={t("nav_homework")}>
      <div className="tg-tabs" role="tablist">
        {(["open", "all", ...(teacher ? [] : ["marks"])] as const).map((k) => (
          <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? "on" : ""} onClick={() => { haptic.select(); setTab(k as typeof tab); }}>
            {t(k === "open" ? (teacher ? "hw_current" : "hw_open") : k === "all" ? "hw_all" : "hw_marks")}
          </button>
        ))}
      </div>
      {teacher && <Link href="/tg/homework/new" className="tg-btn" style={{ marginBottom: 12 }} onClick={() => haptic.tap()}>{t("hw_new")}</Link>}
      {tab === "marks" ? <Marks q={marks} /> : (
        <Async q={q}>
          {(d) => d.data.length === 0 ? <Empty text={t("hw_empty")} icon="📒" /> : <List items={d.data} teacher={d.mode === "teacher"} />}
        </Async>
      )}
    </Page>
  );
}

function List({ items, teacher }: { items: Assignment[]; teacher: boolean }) {
  const { t, tz, settings } = useTg();
  const labels = (settings["homework.status_labels"] ?? {}) as Record<string, string>;
  return (
    <Section>
      {items.map((a) => (
        <Row key={a.id} href={`/tg/homework/${a.id}`} icon="📒" title={a.title}
          subtitle={`${a.group_name ?? ""} · ${a.due_at ? t("hw_due", { date: `${date(a.due_at, tz)} ${time(a.due_at, tz)}` }) : t("hw_no_due")}`}
          right={teacher && a.stats ? <Badge tone={a.stats.submitted > a.stats.graded ? "warn" : "muted"}>{a.stats.submitted}/{a.stats.total}</Badge>
            : a.my ? <Badge tone={TONE[a.my.status]}>{a.my.score != null ? `${a.my.score}/${a.max_score}` : labels[a.my.status] ?? a.my.status}</Badge> : undefined} />
      ))}
    </Section>
  );
}

function Marks({ q }: { q: ReturnType<typeof useApi<{ data: MarkRow[]; average_percent: number | null }>> }) {
  const { t, tz } = useTg();
  return (
    <Async q={q}>
      {(d) => d.data.length === 0 ? <Empty text={t("marks_empty")} icon="⭐️" /> : (
        <>
          {d.average_percent != null && <p className="tg-hint" style={{ margin: "0 4px 10px" }}>{t("marks_avg", { percent: d.average_percent })}</p>}
          <Section>
            {d.data.map((m, i) => (
              <Row key={i} href={m.type === "homework" ? `/tg/homework/${m.assignment_id}` : undefined} icon={m.type === "homework" ? "📒" : "⭐️"}
                title={m.title || t("mark_session")} subtitle={`${m.group_name} · ${date(m.date, tz)}${m.note ? ` · ${m.note}` : ""}`}
                right={<Badge tone={(m.percent ?? 0) >= 80 ? "ok" : (m.percent ?? 0) >= 60 ? "warn" : "bad"}>{m.score}/{m.max}</Badge>} />
            ))}
          </Section>
        </>
      )}
    </Async>
  );
}
