"use client";

import Link from "next/link";
import { useTg } from "./_lib/TgProvider";
import type { Group } from "./_lib/data";
import { haptic } from "./_lib/webapp";
import { Async, Page, useApi } from "./_ui/ui";
import { TodaySessions, UpcomingSessions, teachingGroups } from "./_ui/sessions";

/** Bosh sahifa: rolga qarab bo'limlar. */
export default function TgHome() {
  const { me, t, can, settings } = useTg();
  const groups = useApi<{ data: Group[] }>("groups");
  const unread = useApi<{ unread: number }>("announcements/unread");
  const staff = can("attendance.mark");
  const parent = me.role === "PARENT";
  const roleLabel = (settings["telegram.role_labels"] as Record<string, string> | undefined)?.[me.role] ?? me.role;

  const tiles: { href: string; icon: string; label: string; count?: number }[] = [];
  if (staff) tiles.push({ href: "/tg/today", icon: "📋", label: t("nav_today") });
  if (can("course.view")) tiles.push({ href: "/tg/courses", icon: "📚", label: t("nav_courses") });
  tiles.push({ href: "/tg/schedule", icon: "🗓", label: t("nav_schedule") });
  // Davomat statistikasi: o'quvchi — o'zi, xodim — o'z guruhlari (ko'rinish doirasini API belgilaydi).
  if (can("quiz.take") || staff) tiles.push({ href: "/tg/attendance", icon: "✅", label: t("nav_attendance") });
  if (can("quiz.take")) tiles.push({ href: "/tg/quizzes", icon: "📝", label: t("nav_quizzes") });
  if (parent) tiles.push({ href: "/tg/children", icon: "👨‍👩‍👧", label: t("nav_children") });
  if ((me.role === "STUDENT" || parent) && settings["billing.enabled"] !== false) tiles.push({ href: "/tg/balance", icon: "💰", label: t("nav_balance") });
  tiles.push({ href: "/tg/certificates", icon: "🎓", label: t("nav_certificates") });
  tiles.push({ href: "/tg/news", icon: "📢", label: t("nav_news"), count: unread.data?.unread });

  return (
    <Page>
      <div className="tg-hero">
        <div className="tg-avatar">{me.first_name.slice(0, 1).toUpperCase()}</div>
        <div>
          <h1>{t("hello", { name: me.first_name })}</h1>
          <p className="tg-hint">{roleLabel}</p>
        </div>
      </div>
      <nav className="tg-tiles">
        {tiles.map((x) => (
          <Link key={x.href + x.label} href={x.href} className="tg-tile" onClick={() => haptic.tap()}>
            <span className="ic" aria-hidden>{x.icon}</span>
            {x.label}
            {!!x.count && <span className="count" aria-label={t("news_new")}>{x.count > 99 ? "99+" : x.count}</span>}
          </Link>
        ))}
      </nav>
      <Async q={groups}>
        {(d) => staff
          ? <TodaySessions groups={teachingGroups(d.data, me.id, can("group.manage"))} />
          : <UpcomingSessions groups={d.data.filter((g) => g.active)} days={7} limit={5} />}
      </Async>
    </Page>
  );
}
