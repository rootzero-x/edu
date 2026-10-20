"use client";

import { useState } from "react";
import { useTg } from "../_lib/TgProvider";
import { date, pct, personName, time } from "../_lib/format";
import { Async, Badge, Empty, Page, Progress, Row, Section, useApi } from "../_ui/ui";
import { fmtMoney } from "@/lib/format";
import type { ChildOverview } from "@/lib/family";

/** Farzandlarim (ota-ona): farzandni tanlash, guruhlar va holati, davomat, test natijalari, to'lov. Ma'lumot — GET /me/children. */
export default function TgChildren() {
  const { t, settings, tz } = useTg();
  const q = useApi<{ data: ChildOverview[] }>("me/children");
  const [active, setActive] = useState<number | null>(null);
  const labels = (settings["attendance.status_labels"] ?? {}) as Record<string, string>;
  const emoji = (settings["telegram.attendance_emoji"] ?? {}) as Record<string, string>;
  const weekdays = (t("weekdays") || "Du,Se,Cho,Pa,Ju,Sha,Ya").split(",");
  const tone = (rate: number | null) => (rate == null ? undefined : rate >= 85 ? "ok" : rate >= 65 ? "warn" : "bad");

  return (
    <Page title={t("my_children")}>
      <Async q={q}>
        {(d) => {
          if (!d.data.length) return <Empty text={t("children_empty")} icon="👨‍👩‍👧" />;
          const child = d.data.find((c) => c.id === active) ?? d.data[0];
          const a = child.attendance;
          return (
            <>
              {d.data.length > 1 && (
                <div className="tg-chips" role="tablist" aria-label={t("my_children")}>
                  {d.data.map((c) => (
                    <button key={c.id} type="button" role="tab" aria-selected={c.id === child.id} className={c.id === child.id ? "on" : ""} onClick={() => setActive(c.id)}>
                      {personName(c)}
                    </button>
                  ))}
                </div>
              )}
              {d.data.length === 1 && <p className="tg-hint tg-sub">{personName(child)}</p>}

              <Section title={t("child_groups")}>
                {child.groups.length === 0 ? <Empty text={t("courses_empty")} icon="📚" /> : child.groups.map((g) => (
                  <Row key={g.id} icon="👥" title={g.name}
                    subtitle={[g.course_title, g.schedule.map((x) => `${weekdays[x.weekday - 1] ?? ""} ${x.start}`).join(", "),
                      g.next_session ? `${t("child_next")}: ${date(g.next_session, tz)} ${time(g.next_session, tz)}` : null].filter(Boolean).join(" · ")}
                    right={g.status === "FROZEN" ? <Badge tone="warn">{t("child_status_frozen", { to: (g.frozen_until ?? "").split("-").reverse().join(".") })}</Badge> : undefined} />
                ))}
              </Section>

              <Section title={t("child_attendance", { days: a.days })} right={<b>{a.rate == null ? "—" : pct(a.rate)}</b>}>
                <div className="tg-pad">
                  {a.rate != null && <Progress value={a.rate} tone={tone(a.rate)} />}
                  <div className="tg-stats" style={{ padding: "10px 0 0" }}>
                    {(Object.keys(labels).length ? Object.keys(labels) : ["PRESENT", "LATE", "ABSENT", "EXCUSED"]).map((s) => (
                      <div key={s}><b>{emoji[s]} {a.counts[s] ?? 0}</b><small>{labels[s] ?? s}</small></div>
                    ))}
                  </div>
                </div>
              </Section>
              {a.recent.length > 0 && (
                <Section title={t("child_recent")}>
                  {a.recent.slice(0, 8).map((r, i) => (
                    <Row key={i} icon={emoji[r.status] ?? "•"} title={`${date(r.starts_at, tz)} ${time(r.starts_at, tz)}`}
                      subtitle={[r.group_name, r.topic, r.note].filter(Boolean).join(" · ")} right={labels[r.status] ?? r.status} />
                  ))}
                </Section>
              )}

              {child.quizzes.length > 0 && (
                <Section title={t("child_quizzes")}>
                  {child.quizzes.map((x, i) => (
                    <Row key={i} icon="📝" title={x.title} subtitle={[x.course_title, date(x.submitted_at, tz)].filter(Boolean).join(" · ")}
                      right={<Badge tone={x.passed ? "ok" : "bad"}>{x.percent ?? 0}%</Badge>} />
                  ))}
                </Section>
              )}

              {child.balance && (
                <Section title={t("child_balance")}>
                  <Row href="/tg/balance" icon="💰"
                    title={child.balance.debt > 0 ? t("child_debt", { amount: fmtMoney(child.balance.debt, child.balance.currency) }) : t("child_no_debt")}
                    right={<Badge tone={child.balance.debt > 0 ? "bad" : "ok"}>{fmtMoney(child.balance.balance, child.balance.currency)}</Badge>} />
                </Section>
              )}

              {child.certificates.length > 0 && (
                <Section title={t("child_certificates")}>
                  {child.certificates.map((c) => <Row key={c.code} href={`/c/${c.code}`} icon="🎓" title={c.course_title} subtitle={c.code} />)}
                </Section>
              )}
            </>
          );
        }}
      </Async>
    </Page>
  );
}
