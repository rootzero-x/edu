export type QuestionType = "SINGLE" | "MULTIPLE" | "TEXT";

export interface Question {
  id: string;
  type: QuestionType;
  points: number;
  /** SINGLE/MULTIPLE uchun to'g'ri variant id'lari, TEXT uchun qabul qilinadigan javoblar. */
  correct: readonly string[];
}

export interface QuizRules {
  passPercent: number;
  maxAttempts: number;
  timeLimitSec: number | null;
}

export type Answers = Record<string, readonly string[]>;

const norm = (s: string) => s.trim().toLowerCase().replace(/\s+/g, " ");

export function scoreQuestion(q: Question, given: readonly string[] | undefined): number {
  if (!given || given.length === 0) return 0;
  if (q.type === "TEXT") {
    const ok = q.correct.map(norm);
    return ok.includes(norm(given[0] ?? "")) ? q.points : 0;
  }
  const want = new Set(q.correct);
  const got = new Set(given);
  if (q.type === "SINGLE") return got.size === 1 && want.has(given[0]!) ? q.points : 0;
  // MULTIPLE: to'liq mos bo'lsagina ball (qisman ball yo'q).
  if (got.size !== want.size) return 0;
  for (const g of got) if (!want.has(g)) return 0;
  return q.points;
}

export interface QuizResult {
  score: number;
  maxScore: number;
  percent: number;
  passed: boolean;
}

export function gradeQuiz(
  questions: readonly Question[],
  answers: Answers,
  rules: Pick<QuizRules, "passPercent">,
): QuizResult {
  const maxScore = questions.reduce((s, q) => s + q.points, 0);
  const score = questions.reduce((s, q) => s + scoreQuestion(q, answers[q.id]), 0);
  const percent = maxScore === 0 ? 0 : Math.round((score / maxScore) * 1000) / 10;
  return { score, maxScore, percent, passed: percent >= rules.passPercent };
}

export type AttemptCheck = { ok: true } | { ok: false; reason: "NO_ATTEMPTS_LEFT" | "TIME_EXPIRED" };

export function canSubmit(
  rules: QuizRules,
  previousAttempts: number,
  startedAt: Date,
  now: Date,
  /** Tarmoq kechikishi uchun qo'shimcha soniyalar. */
  graceSec = 15,
): AttemptCheck {
  if (previousAttempts >= rules.maxAttempts) return { ok: false, reason: "NO_ATTEMPTS_LEFT" };
  if (rules.timeLimitSec !== null) {
    const elapsed = (now.getTime() - startedAt.getTime()) / 1000;
    if (elapsed > rules.timeLimitSec + graceSec) return { ok: false, reason: "TIME_EXPIRED" };
  }
  return { ok: true };
}
