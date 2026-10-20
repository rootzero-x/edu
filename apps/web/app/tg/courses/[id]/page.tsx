"use client";

import { useParams } from "next/navigation";
import { useTg } from "../../_lib/TgProvider";
import { Async, Empty, Page, Row, Section, useApi } from "../../_ui/ui";
import { QuizStatus, type QuizItem } from "../../_ui/quiz";

type Course = {
  id: number; title: string; description: string | null;
  modules: { id: number; title: string; lessons: { id: number; title: string; published: boolean }[] }[];
};
export default function TgCourse() {
  const { id } = useParams<{ id: string }>();
  const { t } = useTg();
  const course = useApi<{ course: Course }>(`courses/${id}`);
  const quizzes = useApi<{ data: QuizItem[] }>(`courses/${id}/quizzes`);
  return (
    <Async q={course}>
      {({ course: c }) => (
        <Page title={c.title} subtitle={c.description ?? undefined}>
          {c.modules.length === 0 && <Empty text={t("lessons_empty")} icon="📭" />}
          {c.modules.map((m, mi) => (
            <Section key={m.id} title={`${mi + 1}. ${m.title}`}>
              {m.lessons.length === 0 && <div className="tg-pad tg-hint">{t("lessons_empty")}</div>}
              {m.lessons.map((l, li) => (
                <Row key={l.id} href={`/tg/lessons/${l.id}`} icon={<small>{`${mi + 1}.${li + 1}`}</small>} title={l.title} />
              ))}
            </Section>
          ))}
          {quizzes.data && quizzes.data.data.length > 0 && (
            <Section title={t("quizzes")}>
              {quizzes.data.data.map((q) => (
                <Row key={q.id} href={`/tg/quizzes/${q.id}`} icon={q.is_final ? "🏁" : "📝"} title={q.title} right={<QuizStatus q={q} />} />
              ))}
            </Section>
          )}
        </Page>
      )}
    </Async>
  );
}
