"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { cleanAnswers } from "@/lib/quiz";
import { CheckCircle2, Clock, Send, XCircle } from "lucide-react";
import { Badge, Button, ButtonLink, Card, CardBody, EmptyState, Input, PageHeader, Spinner, cx, useConfirm } from "@/components/ui";
import { call, errorText } from "@/lib/client";
import s from "./take.module.css";
import AccessDenied, { isDenial, type Denial } from "@/components/AccessDenied";

type Q = { id: number; type: "SINGLE" | "MULTIPLE" | "TEXT"; text: string; points: number; options: { id: number; text: string }[] };
type Attempt = { id: number; deadline: string | null; quiz: { id: number; title: string; pass_percent: number }; questions: Q[] };

export default function TakeQuiz({ quizId }: { quizId: number }) {
  const [attempt, setAttempt] = useState<Attempt | null>(null);
  const [answers, setAnswers] = useState<Record<string, string[]>>({});
  const [left, setLeft] = useState<number | null>(null);
  const [result, setResult] = useState<any>(null);
  const [err, setErr] = useState<string | null>(null);
  const [denied, setDenied] = useState<Denial | null>(null);
  const [busy, setBusy] = useState(false);
  const sent = useRef(false);
  const confirm = useConfirm();
  const draftKey = attempt ? `tisu.quiz.${attempt.id}` : null;

  useEffect(() => {
    call("POST", `quizzes/${quizId}/attempts`).then((r) => {
      if (isDenial(r.data)) return setDenied(r.data);
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
    const r = await call("POST", `attempts/${attempt.id}/submit`, { answers: cleanAnswers(answers) });
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

  if (denied) return <AccessDenied denial={denied} />;
  if (err && !attempt) return <Card><EmptyState icon={<XCircle />} title="Testni boshlab bo'lmadi" action={<ButtonLink variant="secondary" href={`/dashboard/quizzes/${quizId}`}>Orqaga</ButtonLink>}>{err}</EmptyState></Card>;
  if (!attempt) return <Card><EmptyState icon={<Spinner />} title="Test yuklanmoqda…" /></Card>;

  const correctOf = (qid: number) => result?.review?.find((x: any) => x.question_id === qid);
  const answered = attempt.questions.filter((q) => (answers[q.id] ?? []).some((v) => v.trim())).length;
  const timer = left !== null && !result && (
    <Badge tone={left < 60 ? "danger" : "neutral"} icon={<Clock />}>{Math.floor(left / 60)}:{String(left % 60).padStart(2, "0")}</Badge>
  );

  async function onSubmit() {
    const missing = attempt!.questions.length - answered;
    if (await confirm({
      title: "Testni topshirasizmi?",
      description: missing ? `${missing} ta savolga javob berilmagan. Topshirgandan keyin o'zgartirib bo'lmaydi.` : "Topshirgandan keyin javoblarni o'zgartirib bo'lmaydi.",
      confirmText: "Topshirish",
    })) submit();
  }

  return (
    <>
      <PageHeader back={{ href: `/dashboard/quizzes/${quizId}`, label: "Test" }} title={attempt.quiz.title} actions={timer || undefined}
        description={result ? undefined : `${answered}/${attempt.questions.length} javob berildi · o'tish bali ${attempt.quiz.pass_percent}%`} />
      {result && (
        <Card className={s.result}>
          <EmptyState
            icon={result.result.passed ? <CheckCircle2 /> : <XCircle />}
            title={result.result.passed ? "Tabriklaymiz, testdan o'tdingiz!" : "Afsuski, bu safar o'tmadingiz"}
            action={<ButtonLink href={`/dashboard/quizzes/${quizId}`} variant="secondary">Natijalarim</ButtonLink>}
          >
            {result.result.score}/{result.result.max_score} ball · {result.result.percent}% (kerak: {attempt.quiz.pass_percent}%){result.result.time_expired && " · vaqt tugagan"}
          </EmptyState>
        </Card>
      )}
      <div className={s.list}>
        {attempt.questions.map((q, i) => {
          const a = answers[q.id] ?? [];
          const rv = correctOf(q.id);
          return (
            <Card key={q.id}>
              <CardBody>
                <div className={s.qHead}>
                  <p className={s.qText} id={`q${q.id}-text`}><b>{i + 1}.</b> {q.text}</p>
                  <span className={s.qMeta}>
                    <Badge>{q.points} ball</Badge>
                    {rv && <Badge tone={rv.earned ? "success" : "danger"}>{rv.earned ? "To'g'ri" : "Noto'g'ri"}</Badge>}
                  </span>
                </div>
                {q.type === "TEXT" ? (
                  <Input disabled={!!result} value={a[0] ?? ""} placeholder="Javobingiz" aria-labelledby={`q${q.id}-text`} onChange={(e) => setAnswers({ ...answers, [q.id]: [e.target.value] })} />
                ) : (
                  <div className={s.options} role={q.type === "SINGLE" ? "radiogroup" : "group"} aria-labelledby={`q${q.id}-text`}>
                    {q.options.map((o) => {
                      const checked = a.includes(String(o.id));
                      const isRight = rv?.correct?.includes(String(o.id));
                      return (
                        <label key={o.id} className={cx(s.opt, checked && s.checked, result && isRight && s.right, result && checked && !isRight && s.wrong)}>
                          <input
                            type={q.type === "SINGLE" ? "radio" : "checkbox"} name={`q${q.id}`} aria-label={o.text} disabled={!!result} checked={checked}
                            onChange={() => setAnswers({ ...answers, [q.id]: q.type === "SINGLE" ? [String(o.id)]
                              : checked ? a.filter((x) => x !== String(o.id)) : [...a, String(o.id)] })}
                          />
                          <span>{o.text}</span>
                        </label>
                      );
                    })}
                    {q.type === "MULTIPLE" && !result && <span className="subtle">Bir nechta to&apos;g&apos;ri javob bo&apos;lishi mumkin</span>}
                  </div>
                )}
                {rv && q.type === "TEXT" && !rv.earned && <p className="subtle" style={{ margin: "8px 0 0" }}>To&apos;g&apos;ri javob: {rv.correct.join(" / ")}</p>}
              </CardBody>
            </Card>
          );
        })}
      </div>
      {!result && (
        <div className={s.footer}>
          <span className="subtle">{answered}/{attempt.questions.length} javob berildi · javoblar qurilmangizda avtomatik saqlanadi</span>
          <Button icon={<Send />} loading={busy} onClick={onSubmit}>Topshirish</Button>
        </div>
      )}
      {err && attempt && <p className="error">{err}</p>}
    </>
  );
}
