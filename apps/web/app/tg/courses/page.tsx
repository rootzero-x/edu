"use client";

import { useTg } from "../_lib/TgProvider";
import type { Group } from "../_lib/data";
import { Async, Badge, Empty, Page, Row, Section, useApi } from "../_ui/ui";

type Course = { id: number; title: string; modules_count: number; published: boolean };

export default function TgCourses() {
  const { t, can } = useTg();
  const groups = useApi<{ data: Group[] }>("groups");
  const all = useApi<{ data: Course[] }>(can("course.manage") ? "courses" : null);
  return (
    <Page title={t("nav_courses")}>
      <Async q={groups}>
        {(d) => {
          const byCourse = new Map<number, { title: string; groups: string[] }>();
          d.data.filter((g) => g.active && g.course).forEach((g) => {
            const c = byCourse.get(g.course.id) ?? { title: g.course.title, groups: [] };
            c.groups.push(g.name);
            byCourse.set(g.course.id, c);
          });
          if (byCourse.size === 0 && !can("course.manage")) return <Empty text={t("courses_empty")} icon="📚" />;
          return byCourse.size > 0 ? (
            <Section>
              {[...byCourse.entries()].map(([id, c]) => (
                <Row key={id} href={`/tg/courses/${id}`} icon="📘" title={c.title} subtitle={c.groups.join(", ")} />
              ))}
            </Section>
          ) : null;
        }}
      </Async>
      {all.data && all.data.data.length > 0 && (
        <Section title={t("all_courses")}>
          {all.data.data.map((c) => (
            <Row key={c.id} href={`/tg/courses/${c.id}`} icon="📗" title={c.title} subtitle={`${t("modules")}: ${c.modules_count}`}
              right={c.published ? undefined : <Badge tone="warn">{t("draft")}</Badge>} />
          ))}
        </Section>
      )}
    </Page>
  );
}
