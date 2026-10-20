"use client";

import { useTg } from "../_lib/TgProvider";
import { Badge } from "./ui";

export type QuizItem = {
  id: number; title: string; is_final: boolean; questions_count: number; max_attempts: number; published: boolean;
  my_attempts?: number; my_best_percent?: number | null; my_passed?: boolean;
};

export function QuizStatus({ q }: { q: QuizItem }) {
  const { t } = useTg();
  if (q.my_passed) return <Badge tone="ok">{`${q.my_best_percent}%`}</Badge>;
  if (q.my_attempts) return <Badge tone="bad">{`${q.my_best_percent ?? 0}%`}</Badge>;
  return <Badge tone="accent">{t("quiz_questions", { n: q.questions_count })}</Badge>;
}
