"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { call, errorText } from "@/lib/client";

type Q = { id: number; type: "SINGLE" | "MULTIPLE" | "TEXT"; text: string; points: number; options: { id: number; text: string }[] };
type Attempt = { id: number; deadline: string | null; quiz: { id: number; title: string; pass_percent: number }; questions: Q[] };

export default function TakeQuiz({ quizId }: { quizId: number }) {
  const [attempt, setAttempt] = useState<Attempt | null>(null);
  const [answers, setAnswers] = useState<Record<string, string[]>>({});
  const [left, setLeft] = useState<number | null>(null);
  const [result, setResult] = useState<any>(null);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const sent = useRef(false);
  const draftKey = attempt ? `tisu.quiz.${attempt.id}` : null;

  useEffect(() => {
    call("POST", `quizzes/${quizId}/attempts`).then((r) => {
      if (!r.ok) return setErr(errorText(r.data));
      setAttempt(r.data.attempt);
      try {
        const saved = localStorage.getItem(`tisu.quiz.${r.data.attempt.id}`);
        if (saved) setAnswers(JSON.parse(saved));
      } catch { /* ignore */ }
    });
  }, [quizId]);

  useEffect(() => {
    if (draftKey) try { localStorage.setItem(draftKey, JSON.stringify(answers)); } catch { /* ignore */ }
  }, [answers, draftKey]);

  const submit = useCallback(async () => {
    if (!attempt || sent.current) return;
    sent.current = true;
    setBusy(true);
    const r = await call("POST", `attempts/${attempt.id}/submit`, { answers });
    setBusy(false);
    if (!r.ok) { sent.current = false; return setErr(errorText(r.data)); }
    try { localStorage.removeItem(`tisu.quiz.${attempt.id}`); } catch { /* ignore */ }
    setResult(r.data);
  }, [attempt, answers]);

  useEffect(() => {
    if (!attempt?.deadline || result) return;
    const end = new Date(attempt.deadline).getTime();
    const t = setInterval(() => {
      const s = Math.max(0, Math.round((end - Date.now()) / 1000));
      setLeft(s);
      if (s === 0) { clearInterval(t); submit(); }
    }, 500);
    return () => clearInterval(t);
  }, [attempt, result, submit]);

  if (err && !attempt) return <p className="error">{err}</p>;
  if (!attempt) return <p className="muted">Yuklanmoqda…</p>;

  const correctOf = (qid: number) => result?.review?.find((x: any) => x.question_id === qid);

  return (
    <>
      <div className="row sticky">
        <h1>{attempt.quiz.title}</h1>
        {left !== null && !result && <span className={left < 60 ? "error" : "muted"}>⏱ {Math.floor(left / 60)}:{String(left % 60).padStart(2, "0")}</span>}
      </div>
      {result && (
        <div className="card">
          <h2>{result.result.passed ? "✅ O'tdingiz" : "❌ O'tmadingiz"}</h2>
          <p>{result.result.score}/{result.result.max_score} ball · {result.result.percent}% (kerak: {attempt.quiz.pass_percent}%)
            {result.result.time_expired && " · vaqt tugagan"}</p>
          <Link className="a" href={`/dashboard/quizzes/${quizId}`}>Natijalar →</Link>
        </div>
      )}
      {attempt.questions.map((q, i) => {
        const a = answers[q.id] ?? [];
        const rv = correctOf(q.id);
        return (
          <div className="card" key={q.id}>
            <p><b>{i + 1}.</b> {q.text} <span className="muted">({q.points} ball)</span>
              {rv && <span className={`tag ${rv.earned ? "ok" : "warn"}`}>{rv.earned ? "to'g'ri" : "noto'g'ri"}</span>}</p>
            {q.type === "TEXT" ? (
              <input disabled={!!result} value={a[0] ?? ""} onChange={(e) => setAnswers({ ...answers, [q.id]: [e.target.value] })} />
            ) : (
              q.options.map((o) => {
                const checked = a.includes(String(o.id));
                const isRight = rv?.correct?.includes(String(o.id));
                return (
                  <label key={o.id} className={`opt ${result && isRight ? "right" : ""}`}>
                    <input
                      type={q.type === "SINGLE" ? "radio" : "checkbox"} name={`q${q.id}`} disabled={!!result} checked={checked}
                      onChange={() => setAnswers({ ...answers, [q.id]: q.type === "SINGLE" ? [String(o.id)]
                        : checked ? a.filter((x) => x !== String(o.id)) : [...a, String(o.id)] })}
                    /> {o.text}
                  </label>
                );
              })
            )}
            {rv && q.type === "TEXT" && !rv.earned && <p className="muted">To&apos;g&apos;ri javob: {rv.correct.join(" / ")}</p>}
          </div>
        );
      })}
      {!result && (
        <div className="toolbar">
          <button className="btn" disabled={busy} onClick={() => window.confirm("Testni topshirasizmi?") && submit()}>Topshirish</button>
          <span className="muted">{Object.keys(answers).length}/{attempt.questions.length} javob berildi</span>
        </div>
      )}
      {err && <p className="error">{err}</p>}
    </>
  );
}
