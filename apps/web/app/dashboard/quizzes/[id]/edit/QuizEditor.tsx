"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";

type Opt = { text: string; is_correct: boolean };
const TYPES: [string, string][] = [["SINGLE", "Bitta javob"], ["MULTIPLE", "Bir nechta javob"], ["TEXT", "Matnli javob"]];

export default function QuizEditor({ quiz, results }: { quiz: any; results: any[] }) {
  const router = useRouter();
  const [msg, setMsg] = useState<{ ok: boolean; t: string } | null>(null);
  const [nq, setNq] = useState({ type: "SINGLE", text: "", points: 1, accepted: "" });
  const [opts, setOpts] = useState<Opt[]>([{ text: "", is_correct: true }, { text: "", is_correct: false }]);
  const [cfg, setCfg] = useState({
    title: quiz.title, pass_percent: quiz.pass_percent, max_attempts: quiz.max_attempts,
    minutes: quiz.time_limit_sec ? Math.round(quiz.time_limit_sec / 60) : 0, is_final: quiz.is_final, shuffle: quiz.shuffle,
  });

  async function run(method: string, path: string, body?: unknown, okText = "Saqlandi.") {
    const r = await call(method, path, body);
    setMsg(r.ok ? { ok: true, t: okText } : { ok: false, t: errorText(r.data) });
    if (r.ok) router.refresh();
    return r.ok;
  }

  return (
    <>
      <p><Link className="a" href={`/dashboard/courses/${quiz.course.id}`}>← {quiz.course.title}</Link></p>
      <div className="row">
        <h1>{quiz.title} {!quiz.published && <span className="tag">yashirin</span>}</h1>
        <button className="btn" onClick={() => run("PATCH", `quizzes/${quiz.id}`, { published: !quiz.published })}>{quiz.published ? "Yashirish" : "E'lon qilish"}</button>
      </div>
      {msg && <p className={msg.ok ? "ok" : "error"}>{msg.t}</p>}

      <div className="card form">
        <h2>Sozlamalar</h2>
        <div className="cols">
          <label>Nomi<input value={cfg.title} onChange={(e) => setCfg({ ...cfg, title: e.target.value })} /></label>
          <label>O&apos;tish bali (%)<input type="number" min={1} max={100} value={cfg.pass_percent} onChange={(e) => setCfg({ ...cfg, pass_percent: Number(e.target.value) })} /></label>
          <label>Urinishlar<input type="number" min={1} max={100} value={cfg.max_attempts} onChange={(e) => setCfg({ ...cfg, max_attempts: Number(e.target.value) })} /></label>
          <label>Vaqt (daqiqa, 0 — cheksiz)<input type="number" min={0} value={cfg.minutes} onChange={(e) => setCfg({ ...cfg, minutes: Number(e.target.value) })} /></label>
        </div>
        <label><input type="checkbox" checked={cfg.is_final} onChange={(e) => setCfg({ ...cfg, is_final: e.target.checked })} /> Yakuniy test (sertifikat sharti)</label>
        <label><input type="checkbox" checked={cfg.shuffle} onChange={(e) => setCfg({ ...cfg, shuffle: e.target.checked })} /> Savol va variantlarni aralashtirish</label>
        <button className="btn" onClick={() => run("PATCH", `quizzes/${quiz.id}`, {
          title: cfg.title, pass_percent: cfg.pass_percent, max_attempts: cfg.max_attempts,
          time_limit_sec: cfg.minutes > 0 ? cfg.minutes * 60 : null, is_final: cfg.is_final, shuffle: cfg.shuffle,
        })}>Saqlash</button>
      </div>

      <h2>Savollar ({quiz.questions.length})</h2>
      {quiz.questions.map((q: any, i: number) => (
        <div className="card" key={q.id}>
          <div className="row">
            <p><b>{i + 1}.</b> {q.text} <span className="muted">· {TYPES.find(([t]) => t === q.type)?.[1]} · {q.points} ball</span></p>
            <button className="link danger" onClick={() => window.confirm("Savolni o'chirasizmi?") && run("DELETE", `questions/${q.id}`, undefined, "O'chirildi.")}>o&apos;chirish</button>
          </div>
          {q.type === "TEXT"
            ? <p className="muted">Qabul qilinadi: {(q.accepted_answers ?? []).join(" / ")}</p>
            : <ul className="plain">{q.options.map((o: any) => <li key={o.id}>{o.is_correct ? "✅" : "▫️"} {o.text}</li>)}</ul>}
        </div>
      ))}

      <div className="card form">
        <h2>Yangi savol</h2>
        <div className="cols">
          <label>Turi<select value={nq.type} onChange={(e) => setNq({ ...nq, type: e.target.value })}>{TYPES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></label>
          <label>Ball<input type="number" min={1} max={100} value={nq.points} onChange={(e) => setNq({ ...nq, points: Number(e.target.value) })} /></label>
        </div>
        <label>Savol matni<textarea rows={2} value={nq.text} onChange={(e) => setNq({ ...nq, text: e.target.value })} /></label>
        {nq.type === "TEXT" ? (
          <label>Qabul qilinadigan javoblar (har biri yangi qatorda)<textarea rows={3} value={nq.accepted} onChange={(e) => setNq({ ...nq, accepted: e.target.value })} /></label>
        ) : (
          <>
            {opts.map((o, i) => (
              <div className="inline" key={i}>
                <input type={nq.type === "SINGLE" ? "radio" : "checkbox"} name="correct" checked={o.is_correct}
                  onChange={(e) => setOpts(opts.map((x, j) => nq.type === "SINGLE" ? { ...x, is_correct: j === i } : j === i ? { ...x, is_correct: e.target.checked } : x))} />
                <input placeholder={`Variant ${i + 1}`} value={o.text} onChange={(e) => setOpts(opts.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)))} />
                {opts.length > 2 && <button type="button" className="link danger" onClick={() => setOpts(opts.filter((_, j) => j !== i))}>×</button>}
              </div>
            ))}
            {opts.length < 12 && <button type="button" className="link" onClick={() => setOpts([...opts, { text: "", is_correct: false }])}>+ variant</button>}
          </>
        )}
        <button className="btn" onClick={async () => {
          const body: any = { type: nq.type, text: nq.text, points: nq.points };
          if (nq.type === "TEXT") body.accepted_answers = nq.accepted.split("\n").map((x) => x.trim()).filter(Boolean);
          else body.options = opts.filter((o) => o.text.trim());
          if (await run("POST", `quizzes/${quiz.id}/questions`, body, "Savol qo'shildi.")) {
            setNq({ ...nq, text: "", accepted: "" });
            setOpts([{ text: "", is_correct: true }, { text: "", is_correct: false }]);
          }
        }}>Savol qo&apos;shish</button>
      </div>

      <h2>Natijalar ({results.length})</h2>
      <table>
        <thead><tr><th>O&apos;quvchi</th><th>Ball</th><th>%</th><th>Natija</th></tr></thead>
        <tbody>
          {results.map((r) => (
            <tr key={r.id}><td>{r.first_name} {r.last_name}</td><td>{r.score}/{r.max_score}</td><td>{r.percent}</td>
              <td>{r.passed ? <span className="tag ok">o&apos;tdi</span> : <span className="tag warn">o&apos;tmadi</span>}</td></tr>
          ))}
        </tbody>
      </table>
    </>
  );
}
