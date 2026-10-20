"use client";

import { useEffect, useState } from "react";
import { tg } from "../_lib/api";
import { useTg } from "../_lib/TgProvider";
import type { Group } from "../_lib/data";
import { Async, Empty, Loading, Page, Row, Section, useApi } from "../_ui/ui";
import { QuizStatus, type QuizItem } from "../_ui/quiz";

function CourseQuizzes({ courses }: { courses: { id: number; title: string }[] }) {
  const { t } = useTg();
  const [lists, setLists] = useState<{ course: { id: number; title: string }; quizzes: QuizItem[] }[] | null>(null);
  useEffect(() => {
    Promise.all(courses.map(async (c) => {
      const r = await tg<{ data: QuizItem[] }>("GET", `courses/${c.id}/quizzes`);
      return { course: c, quizzes: r.ok ? r.data.data : [] };
    })).then(setLists);
  }, [courses.map((c) => c.id).join(",")]);
  if (!lists) return <Loading />;
  const nonEmpty = lists.filter((l) => l.quizzes.length > 0);
  if (nonEmpty.length === 0) return <Empty text={t("quizzes_empty")} icon="📝" />;
  return (
    <>
      {nonEmpty.map((l) => (
        <Section key={l.course.id} title={l.course.title}>
          {l.quizzes.map((q) => (
            <Row key={q.id} href={`/tg/quizzes/${q.id}`} icon={q.is_final ? "🏁" : "📝"} title={q.title} right={<QuizStatus q={q} />} />
          ))}
        </Section>
      ))}
    </>
  );
}

export default function TgQuizzes() {
  const { t } = useTg();
  const groups = useApi<{ data: Group[] }>("groups");
  return (
    <Page title={t("nav_quizzes")}>
      <Async q={groups}>
        {(d) => {
          const seen = new Map<number, { id: number; title: string }>();
          d.data.filter((g) => g.active && g.course).forEach((g) => seen.set(g.course.id, g.course));
          return seen.size ? <CourseQuizzes courses={[...seen.values()]} /> : <Empty text={t("courses_empty")} icon="📝" />;
        }}
      </Async>
    </Page>
  );
}
