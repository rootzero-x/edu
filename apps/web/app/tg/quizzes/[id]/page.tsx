"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { cleanAnswers } from "@/lib/quiz";
import { useParams } from "next/navigation";
import { errorText, tg } from "../../_lib/api";
import { useTg } from "../../_lib/TgProvider";
import { confirmDialog, haptic, openLink, setBackGuard, webApp } from "../../_lib/webapp";
import { date } from "../../_lib/format";
import { Async, Badge, Empty, MainButton, Page, Row, Section, useApi } from "../../_ui/ui";

type Info = {
  quiz: { id: number; title: string; is_final: boolean; pass_percent: number; max_attempts: number; time_limit_sec: number | null; questions_count: number; course: { title: string } };
  attempts: { id: number; started_at: string; submitted_at: string | null; percent: number | null; passed: boolean | null }[];
  can_take: boolean;
  can_start?: boolean;
};
type Question = { id: number; type: "SINGLE" | "MULTIPLE" | "TEXT"; text: string; points: number; options: { id: number; text: string }[] };
type Attempt = { id: number; deadline: string | null; quiz: { id: number; title: string }; questions: Question[] };
type Result = {
  result: { score: number; max_score: number; percent: number; passed: boolean; time_expired: boolean };
  review: { question_id: number; correct: string[]; earned: number; points: number }[] | null;
};
type Answers = Record<string, string[]>;

const storeKey = (attemptId: number) => `tg.quiz.${attemptId}`;

function loadAnswers(attemptId: number): Answers {
  try {
    return JSON.parse(sessionStorage.getItem(storeKey(attemptId)) ?? "{}");
  } catch {
    return {};
  }
}

function saveAnswers(attemptId: number, a: Answers) {
  try {
    sessionStorage.setItem(storeKey(attemptId), JSON.stringify(a));
  } catch {
    /* saqlash ixtiyoriy */
  }
}

function fmtLeft(sec: number) {
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}:${String(s).padStart(2, "0")}`;
}

/** Test: ma'lumot → urinish (savollar birma-bir, taymer) → natija va tahlil. */
export default function TgQuiz() {
  const { id } = useParams<{ id: string }>();
  const { t, tz, settings } = useTg();
  const info = useApi<Info>(`quizzes/${id}`);
  const [attempt, setAttempt] = useState<Attempt | null>(null);
  const [result, setResult] = useState<{ res: Result; attempt: Attempt; answers: Answers } | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const starting = useRef(false);

  async function start(isNew: boolean) {
    if (starting.current) return;
    // Yangi urinish darhol hisoblanadi va vaqt yura boshlaydi — web'dagi kabi avval tasdiq so'raymiz.
    if (isNew && !(await confirmDialog(t("quiz_start_confirm")))) return;
    starting.current = true;
    setBusy(true);
    setErr(null);
    const r = await tg<{ attempt: Attempt }>("POST", `quizzes/${id}/attempts`);
    starting.current = false;
    setBusy(false);
    if (!r.ok) {
      haptic.error();
      return setErr(errorText(r.data, t("error")));
    }
    haptic.success();
    setAttempt(r.data.attempt);
  }

  if (result) return <ResultView {...result} onBack={() => { setResult(null); setAttempt(null); info.reload(); }} />;
  if (attempt) return <AttemptView attempt={attempt} onDone={(res, answers) => setResult({ res, attempt, answers })} />;

  return (
    <Async q={info} title={t("quiz")}>
      {({ quiz, attempts, can_take, can_start }) => {
        // Boshqaruvchilar (javob kalitini ko'radiganlar) uchun API urinishlarsiz to'liq testni qaytaradi.
        if (!Array.isArray(attempts)) {
          const site = String(settings["site.url"] ?? "").replace(/\/$/, "");
          return (
            <Page title={quiz.title} subtitle={quiz.course?.title}>
              <Empty text={t("quiz_manage_on_web")} icon="🛠" />
              {site && <MainButton text={t("open_on_web")} onClick={() => openLink(`${site}/dashboard/quizzes/${quiz.id}/edit`)} />}
            </Page>
          );
        }
        const used = attempts.length;
        const open = attempts.find((a) => !a.submitted_at);
        const best = Math.max(-1, ...attempts.filter((a) => a.percent !== null).map((a) => a.percent!));
        const left = quiz.max_attempts - used;
        // O'tgan testni qayta boshlash — faqat sozlama ruxsat bersa (server can_start da hisoblaydi).
        const canStart = can_start ?? (can_take && (Boolean(open) || (left > 0 && !attempts.some((a) => a.passed))));
        return (
          <Page title={quiz.title} subtitle={quiz.course?.title}>
            <Section>
              <Row icon="❓" title={t("quiz_questions", { n: quiz.questions_count })} />
              <Row icon="⏱" title={quiz.time_limit_sec ? t("quiz_time", { min: Math.round(quiz.time_limit_sec / 60) }) : t("quiz_no_time")} />
              <Row icon="🎯" title={t("quiz_pass", { percent: quiz.pass_percent })} />
              {can_take && <Row icon="🔁" title={t("quiz_attempts", { used, max: quiz.max_attempts })} />}
              {best >= 0 && <Row icon="🏆" title={t("quiz_best", { percent: best })} />}
            </Section>
            {attempts.filter((a) => a.submitted_at).length > 0 && (
              <Section>
                {attempts.filter((a) => a.submitted_at).map((a) => (
                  <Row key={a.id} title={date(a.submitted_at!, tz)} right={<Badge tone={a.passed ? "ok" : "bad"}>{`${a.percent}%`}</Badge>} />
                ))}
              </Section>
            )}
            {!can_take && <Empty text={t("quiz_not_allowed")} icon="🔒" />}
            {err && <p className="tg-hint tg-msg bad" role="alert">{err}</p>}
            <MainButton
              text={open ? t("quiz_continue") : t("quiz_start")}
              onClick={() => start(!open)}
              progress={busy}
              hidden={!canStart}
            />
          </Page>
        );
      }}
    </Async>
  );
}

function AttemptView({ attempt, onDone }: { attempt: Attempt; onDone: (r: Result, a: Answers) => void }) {
  const { t } = useTg();
  const [answers, setAnswers] = useState<Answers>(() => loadAnswers(attempt.id));
  const [i, setI] = useState(0);
  const [busy, setBusy] = useState(false);
  const [left, setLeft] = useState<number | null>(null);
  const submitted = useRef(false);
  const autoTried = useRef(false);
  const qs = attempt.questions;
  const q = qs[i];
  const last = i === qs.length - 1;

  useEffect(() => saveAnswers(attempt.id, answers), [attempt.id, answers]);

  // Yopishdan va BackButton bilan chiqishdan oldin so'rash — urinish tasodifan tashlab ketilmasin.
  useEffect(() => {
    const wa = webApp();
    try {
      wa?.enableClosingConfirmation();
    } catch {
      /* eski versiya */
    }
    setBackGuard(() => (submitted.current ? Promise.resolve(true) : confirmDialog(t("quiz_leave_confirm"))));
    return () => {
      setBackGuard(null);
      try {
        wa?.disableClosingConfirmation();
      } catch {
        /* eski versiya */
      }
    };
  }, [t]);

  const submit = useCallback(async (force = false) => {
    // Vaqt tugaganda avto-topshirish bir marta urinadi (xatoda har soniya qayta yuborib, ogohlantirish chiqarmasin).
    if (submitted.current || (force && autoTried.current)) return;
    if (force) autoTried.current = true;
    if (!force) {
      const unanswered = qs.filter((x) => !(answers[x.id]?.length && answers[x.id].some((v) => v.trim() !== ""))).length;
      const ok = await confirmDialog(unanswered ? t("quiz_unanswered", { n: unanswered }) : t("quiz_confirm_submit"));
      if (!ok) return;
    }
    submitted.current = true;
    setBusy(true);
    const r = await tg<Result>("POST", `attempts/${attempt.id}/submit`, { answers: cleanAnswers(answers) });
    setBusy(false);
    if (!r.ok) {
      submitted.current = false;
      haptic.error();
      webApp()?.showAlert(errorText(r.data, t("error")));
      return;
    }
    try {
      sessionStorage.removeItem(storeKey(attempt.id));
    } catch {
      /* ixtiyoriy */
    }
    r.data.result.passed ? haptic.success() : haptic.warning();
    onDone(r.data, answers);
  }, [answers, attempt.id, onDone, qs, t]);

  // Taymer: vaqt tugasa avtomatik topshiriladi.
  useEffect(() => {
    if (!attempt.deadline) return;
    const end = new Date(attempt.deadline).getTime();
    const tick = () => {
      const s = Math.max(0, Math.round((end - Date.now()) / 1000));
      setLeft(s);
      if (s === 0) submit(true);
    };
    tick();
    const h = setInterval(tick, 1000);
    return () => clearInterval(h);
  }, [attempt.deadline, submit]);

  const set = (qid: number, v: string[]) => {
    haptic.select();
    setAnswers((a) => ({ ...a, [qid]: v }));
  };
  const toggle = (opt: string) => {
    const cur = answers[q.id] ?? [];
    if (q.type === "SINGLE") set(q.id, [opt]);
    else set(q.id, cur.includes(opt) ? cur.filter((x) => x !== opt) : [...cur, opt]);
  };
  const go = (n: number) => {
    haptic.tap();
    setI(Math.max(0, Math.min(qs.length - 1, n)));
    window.scrollTo({ top: 0 });
  };

  if (!q) return <Page title={attempt.quiz.title}><Empty text={t("quizzes_empty")} /></Page>;
  const chosen = answers[q.id] ?? [];
  return (
    <Page>
      <div className={`tg-timer${left !== null && left <= 60 ? " low" : ""}`}>
        <span>{t("question", { n: i + 1, total: qs.length })}</span>
        {left !== null && <span>{t("quiz_time_left", { time: fmtLeft(left) })}</span>}
      </div>
      <Section>
        <div className="tg-question">
          <h3 id={`tgq${q.id}`}>{q.text}</h3>
          {q.type === "TEXT" ? (
            <input className="tg-input" placeholder={t("quiz_text_answer")} aria-labelledby={`tgq${q.id}`} value={chosen[0] ?? ""}
              onChange={(e) => setAnswers((a) => ({ ...a, [q.id]: [e.target.value] }))} />
          ) : (
            <div role={q.type === "SINGLE" ? "radiogroup" : "group"} aria-labelledby={`tgq${q.id}`}>
              {q.type === "MULTIPLE" && <p className="tg-hint" style={{ marginBottom: 10 }}>{t("quiz_multiple_hint")}</p>}
              {q.options.map((o) => {
                const on = chosen.includes(String(o.id));
                return (
                  <button key={o.id} type="button" role={q.type === "SINGLE" ? "radio" : "checkbox"} aria-checked={on}
                    className={`tg-option${q.type === "MULTIPLE" ? " multi" : ""}${on ? " on" : ""}`} onClick={() => toggle(String(o.id))}>
                    <span className="mark" aria-hidden>{on ? "✓" : ""}</span>
                    <span>{o.text}</span>
                  </button>
                );
              })}
            </div>
          )}
        </div>
      </Section>
      <div className="tg-nav">
        <button className="tg-btn ghost" disabled={i === 0} onClick={() => go(i - 1)}>‹</button>
        <button className="tg-btn ghost" disabled={last} onClick={() => go(i + 1)}>›</button>
      </div>
      <Section>
        <div className="tg-dots">
          {qs.map((x, n) => (
            <button key={x.id} className={`${answers[x.id]?.some((v) => v.trim() !== "") ? "done" : ""}${n === i ? " cur" : ""}`} onClick={() => go(n)}>{n + 1}</button>
          ))}
        </div>
      </Section>
      <MainButton text={last ? t("quiz_submit") : `${t("question", { n: i + 2, total: qs.length })} ›`} onClick={() => (last ? submit() : go(i + 1))} progress={busy} />
    </Page>
  );
}

function ResultView({ res, attempt, answers, onBack }: { res: Result; attempt: Attempt; answers: Answers; onBack: () => void }) {
  const { t } = useTg();
  // Natija oxirgi savol pastida emas, sahifa boshida ko'rinsin.
  useEffect(() => {
    window.scrollTo({ top: 0 });
  }, []);
  const r = res.result;
  const review = new Map((res.review ?? []).map((x) => [x.question_id, x]));
  return (
    <Page>
      <Section>
        <div className="tg-result">
          <div className="tg-emoji">{r.passed ? "🎉" : "📘"}</div>
          <div className="big">{`${r.percent}%`}</div>
          <p className="tg-hint">{t("quiz_result", { score: r.score, max: r.max_score, percent: r.percent })}</p>
          <p style={{ marginTop: 8, fontWeight: 600 }}>{r.passed ? t("quiz_passed") : t("quiz_failed")}</p>
          {r.time_expired && <p className="tg-hint">{t("quiz_time_expired")}</p>}
        </div>
      </Section>
      {res.review && attempt.questions.map((q, n) => {
        const rv = review.get(q.id);
        const given = answers[q.id] ?? [];
        const ok = rv ? rv.earned === rv.points : false;
        return (
          <Section key={q.id} title={t("question", { n: n + 1, total: attempt.questions.length })} right={<Badge tone={ok ? "ok" : "bad"}>{ok ? t("correct") : t("wrong")}</Badge>}>
            <div className="tg-question">
              <h3 id={`tgq${q.id}`}>{q.text}</h3>
              {q.type === "TEXT" ? (
                <>
                  <div className={`tg-option ${ok ? "correct" : "wrong"}`}><span>{given[0] || "—"}</span></div>
                  {!ok && rv && <p className="tg-hint">{t("correct")}: {rv.correct.join(" / ")}</p>}
                </>
              ) : q.options.map((o) => {
                const id = String(o.id);
                const isCorrect = rv?.correct.includes(id);
                const picked = given.includes(id);
                return (
                  <div key={o.id} className={`tg-option${q.type === "MULTIPLE" ? " multi" : ""}${picked ? " on" : ""}${isCorrect ? " correct" : picked ? " wrong" : ""}`}>
                    <span className="mark">{isCorrect ? "✓" : picked ? "✕" : ""}</span>
                    <span>{o.text}</span>
                  </div>
                );
              })}
            </div>
          </Section>
        );
      })}
      <MainButton text={t("quiz_back")} onClick={onBack} />
    </Page>
  );
}
