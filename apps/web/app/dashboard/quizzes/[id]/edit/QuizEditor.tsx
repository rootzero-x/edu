"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { CheckCircle2, Circle, ClipboardList, MoreHorizontal, Pencil, Plus, Save, Trash2, Trophy } from "lucide-react";
import {
  Badge, Button, Card, CardBody, CardHeader, Checkbox, Dropdown, EmptyState, Field, Input, PageHeader, Select, Switch, Table, Td, Textarea, Th,
  useConfirm, usePrompt, useToast,
} from "@/components/ui";
import { call, errorText } from "@/lib/client";
import s from "./editor.module.css";

type Opt = { text: string; is_correct: boolean };
const TYPES: [string, string][] = [["SINGLE", "Bitta javob"], ["MULTIPLE", "Bir nechta javob"], ["TEXT", "Matnli javob"]];
const typeLabel = (t: string) => TYPES.find(([v]) => v === t)?.[1] ?? t;
const blankOpts = (): Opt[] => [{ text: "", is_correct: true }, { text: "", is_correct: false }];

export default function QuizEditor({ quiz, results }: { quiz: any; results: any[] }) {
  const router = useRouter();
  const toast = useToast();
  const confirm = useConfirm();
  const prompt = usePrompt();
  const [busy, setBusy] = useState<string | null>(null);
  const [nq, setNq] = useState({ type: "SINGLE", text: "", points: 1, accepted: "" });
  const [opts, setOpts] = useState<Opt[]>(blankOpts);
  const fromQuiz = () => ({
    title: quiz.title, pass_percent: quiz.pass_percent, max_attempts: quiz.max_attempts,
    minutes: quiz.time_limit_sec ? Math.round(quiz.time_limit_sec / 60) : 0, is_final: quiz.is_final, shuffle: quiz.shuffle,
  });
  const [cfg, setCfg] = useState(fromQuiz);
  useEffect(() => setCfg(fromQuiz()), [quiz]); // eslint-disable-line react-hooks/exhaustive-deps

  async function run(key: string, method: string, path: string, body?: unknown, ok = "Saqlandi") {
    setBusy(key);
    const r = await call(method, path, body);
    setBusy(null);
    if (!r.ok) { toast.error("Saqlanmadi", errorText(r.data)); return false; }
    toast.success(ok);
    router.refresh();
    return true;
  }

  async function addQuestion(e: React.FormEvent) {
    e.preventDefault();
    const body: Record<string, unknown> = { type: nq.type, text: nq.text, points: nq.points };
    if (nq.type === "TEXT") body.accepted_answers = nq.accepted.split("\n").map((x) => x.trim()).filter(Boolean);
    else body.options = opts.filter((o) => o.text.trim());
    if (await run("q", "POST", `quizzes/${quiz.id}/questions`, body, "Savol qo'shildi")) {
      setNq({ ...nq, text: "", accepted: "" });
      setOpts(blankOpts());
    }
  }

  async function removeQuestion(q: any) {
    if (await confirm({ title: "Savol o'chirilsinmi?", description: q.text, confirmText: "O'chirish", tone: "danger" })) {
      run(`del${q.id}`, "DELETE", `questions/${q.id}`, undefined, "Savol o'chirildi");
    }
  }

  /** Savolni tahrirlash: variantlar har qatorda, to'g'ri javob oldida «*». */
  const editQuestion = (q: any) => prompt({
    title: `${typeLabel(q.type)} savol`,
    fields: [
      { name: "text", label: "Savol matni", type: "textarea", required: true, defaultValue: q.text },
      { name: "points", label: "Ball", type: "number", required: true, defaultValue: String(q.points) },
      q.type === "TEXT"
        ? { name: "answers", label: "Qabul qilinadigan javoblar", type: "textarea", required: true, defaultValue: (q.accepted_answers ?? []).join("\n"), hint: "Har biri yangi qatorda" }
        : { name: "answers", label: "Variantlar", type: "textarea", required: true, defaultValue: q.options.map((o: any) => `${o.is_correct ? "*" : ""}${o.text}`).join("\n"), hint: "Har biri yangi qatorda; to'g'ri javob(lar) oldiga * qo'ying" },
    ],
    submitText: "Saqlash",
    onSubmit: async (v) => {
      const lines = v.answers.split("\n").map((x: string) => x.trim()).filter(Boolean);
      const body: Record<string, unknown> = { text: v.text, points: Number(v.points) || 1 };
      if (q.type === "TEXT") body.accepted_answers = lines;
      else body.options = lines.map((l: string) => ({ text: l.replace(/^\*\s*/, ""), is_correct: l.startsWith("*") }));
      const r = await call("PATCH", `questions/${q.id}`, body);
      if (!r.ok) return errorText(r.data);
      toast.success("Savol saqlandi");
      router.refresh();
    },
  });

  async function deleteQuiz() {
    if (!(await confirm({ title: "Test o'chirilsinmi?", description: `«${quiz.title}» va uning savollari o'chiriladi. Topshirganlar bo'lsa, o'chirib bo'lmaydi — yashiring.`, confirmText: "O'chirish", tone: "danger" }))) return;
    const r = await call("DELETE", `quizzes/${quiz.id}`);
    if (!r.ok) return toast.error("O'chirilmadi", errorText(r.data));
    toast.success("Test o'chirildi");
    router.push(`/dashboard/courses/${quiz.course.id}`);
  }

  const totalPoints = quiz.questions.reduce((n: number, q: any) => n + q.points, 0);

  return (
    <>
      <PageHeader
        back={{ href: `/dashboard/courses/${quiz.course.id}`, label: quiz.course.title }}
        title={quiz.title}
        description={`${quiz.questions.length} savol · ${totalPoints} ball`}
        badge={quiz.published ? <Badge tone="success" dot>E&apos;lon qilingan</Badge> : <Badge tone="warning" dot>Yashirin</Badge>}
        actions={
          <>
            <Switch label="E'lon qilish" checked={quiz.published}
              onChange={(e) => run("pub", "PATCH", `quizzes/${quiz.id}`, { published: e.target.checked }, e.target.checked ? "Test e'lon qilindi" : "Test yashirildi")} />
            <Dropdown
              trigger={(p) => <Button variant="ghost" iconOnly aria-label="Test amallari" icon={<MoreHorizontal />} {...p} />}
              items={[{ label: "Testni o'chirish", icon: <Trash2 />, danger: true, onSelect: deleteQuiz }]}
            />
          </>
        }
      />

      <Card>
        <CardHeader title="Sozlamalar" />
        <CardBody>
          <form className={s.form} onSubmit={(e) => {
            e.preventDefault();
            run("cfg", "PATCH", `quizzes/${quiz.id}`, {
              title: cfg.title, pass_percent: cfg.pass_percent, max_attempts: cfg.max_attempts,
              time_limit_sec: cfg.minutes > 0 ? cfg.minutes * 60 : null, is_final: cfg.is_final, shuffle: cfg.shuffle,
            });
          }}>
            <div className={s.cols}>
              <Field label="Nomi" required><Input value={cfg.title} onChange={(e) => setCfg({ ...cfg, title: e.target.value })} required /></Field>
              <Field label="O'tish bali (%)"><Input type="number" min={1} max={100} value={cfg.pass_percent} onChange={(e) => setCfg({ ...cfg, pass_percent: Number(e.target.value) })} /></Field>
              <Field label="Urinishlar soni"><Input type="number" min={1} max={100} value={cfg.max_attempts} onChange={(e) => setCfg({ ...cfg, max_attempts: Number(e.target.value) })} /></Field>
              <Field label="Vaqt (daqiqa)" hint="0 — cheksiz"><Input type="number" min={0} value={cfg.minutes} onChange={(e) => setCfg({ ...cfg, minutes: Number(e.target.value) })} /></Field>
            </div>
            <div className={s.switches}>
              <Switch label="Yakuniy test (sertifikat sharti)" checked={cfg.is_final} onChange={(e) => setCfg({ ...cfg, is_final: e.target.checked })} />
              <Switch label="Savol va variantlarni aralashtirish" checked={cfg.shuffle} onChange={(e) => setCfg({ ...cfg, shuffle: e.target.checked })} />
            </div>
            <div><Button type="submit" icon={<Save />} loading={busy === "cfg"}>Saqlash</Button></div>
          </form>
        </CardBody>
      </Card>

      <h2 className={s.h2}>Savollar</h2>
      {quiz.questions.length === 0 && <Card><EmptyState compact icon={<ClipboardList />} title="Hali savol yo'q">Pastdagi formadan birinchi savolni qo&apos;shing.</EmptyState></Card>}
      <div className={s.list}>
        {quiz.questions.map((q: any, i: number) => (
          <Card key={q.id}>
            <CardBody>
              <div className={s.qHead}>
                <p className={s.qText}><b>{i + 1}.</b> {q.text}</p>
                <span className={s.qMeta}>
                  <Badge>{typeLabel(q.type)}</Badge>
                  <Badge tone="accent">{q.points} ball</Badge>
                  <Button variant="ghost" size="sm" iconOnly aria-label="Savolni tahrirlash" icon={<Pencil />} onClick={() => editQuestion(q)} />
                  <Button variant="ghost" size="sm" iconOnly aria-label="Savolni o'chirish" icon={<Trash2 />} loading={busy === `del${q.id}`} onClick={() => removeQuestion(q)} />
                </span>
              </div>
              {q.type === "TEXT" ? (
                <p className="subtle" style={{ margin: 0 }}>Qabul qilinadi: {(q.accepted_answers ?? []).join(" / ")}</p>
              ) : (
                <ul className={s.opts}>
                  {q.options.map((o: any) => (
                    <li key={o.id} className={o.is_correct ? s.correct : undefined}>
                      {o.is_correct ? <CheckCircle2 aria-label="To'g'ri" /> : <Circle aria-hidden />} {o.text}
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>
        ))}
      </div>

      <Card style={{ marginTop: 16 }}>
        <CardHeader title="Yangi savol" />
        <CardBody>
          <form className={s.form} onSubmit={addQuestion}>
            <div className={s.cols}>
              <Field label="Turi">
                <Select value={nq.type} onChange={(e) => { setNq({ ...nq, type: e.target.value }); setOpts(blankOpts()); }}>
                  {TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </Select>
              </Field>
              <Field label="Ball"><Input type="number" min={1} max={100} value={nq.points} onChange={(e) => setNq({ ...nq, points: Number(e.target.value) })} /></Field>
            </div>
            <Field label="Savol matni" required><Textarea rows={2} value={nq.text} onChange={(e) => setNq({ ...nq, text: e.target.value })} required /></Field>
            {nq.type === "TEXT" ? (
              <Field label="Qabul qilinadigan javoblar" hint="Har biri yangi qatorda; katta-kichik harf va ortiqcha bo'sh joy hisobga olinmaydi">
                <Textarea rows={3} value={nq.accepted} onChange={(e) => setNq({ ...nq, accepted: e.target.value })} />
              </Field>
            ) : (
              <Field label="Variantlar" hint={nq.type === "SINGLE" ? "To'g'ri javobni belgilang" : "To'g'ri javoblarning hammasini belgilang"}>
                <div className={s.optEdit}>
                  {opts.map((o, i) => (
                    <div key={i} className={s.optRow}>
                      {nq.type === "SINGLE" ? (
                        <input type="radio" name="correct" aria-label={`Variant ${i + 1} to'g'ri`} checked={o.is_correct}
                          onChange={() => setOpts(opts.map((x, j) => ({ ...x, is_correct: j === i })))} />
                      ) : (
                        <Checkbox aria-label={`Variant ${i + 1} to'g'ri`} checked={o.is_correct}
                          onChange={(e) => setOpts(opts.map((x, j) => (j === i ? { ...x, is_correct: e.target.checked } : x)))} />
                      )}
                      <Input placeholder={`Variant ${i + 1}`} value={o.text} onChange={(e) => setOpts(opts.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)))} />
                      {opts.length > 2 && <Button type="button" variant="ghost" iconOnly aria-label="Variantni o'chirish" icon={<Trash2 />} onClick={() => setOpts(opts.filter((_, j) => j !== i))} />}
                    </div>
                  ))}
                  {opts.length < 12 && <div><Button type="button" variant="secondary" size="sm" icon={<Plus />} onClick={() => setOpts([...opts, { text: "", is_correct: false }])}>Variant</Button></div>}
                </div>
              </Field>
            )}
            <div><Button type="submit" icon={<Plus />} loading={busy === "q"}>Savol qo&apos;shish</Button></div>
          </form>
        </CardBody>
      </Card>

      <Card style={{ marginTop: 16 }}>
        <CardHeader title="Natijalar" description={`${results.length} ta urinish`} />
        {results.length === 0 ? <EmptyState compact icon={<Trophy />} title="Hali hech kim topshirmagan" /> : (
          <Table>
            <thead><tr><Th>O&apos;quvchi</Th><Th>Ball</Th><Th>%</Th><Th>Natija</Th></tr></thead>
            <tbody>
              {results.map((r) => (
                <tr key={r.id}>
                  <Td>{[r.first_name, r.last_name].filter(Boolean).join(" ")}<div className="subtle">{r.phone}</div></Td>
                  <Td>{r.score}/{r.max_score}</Td>
                  <Td>{r.percent}</Td>
                  <Td>{r.passed ? <Badge tone="success">O&apos;tdi</Badge> : <Badge tone="warning">O&apos;tmadi</Badge>}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}
