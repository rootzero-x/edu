"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";
import { useState } from "react";

export default function Quizzes({ courseId, quizzes, canManage }: { courseId: number; quizzes: any[]; canManage: boolean }) {
  const router = useRouter();
  const [err, setErr] = useState<string | null>(null);
  return (
    <div className="card">
      <div className="row">
        <h2>Testlar</h2>
        {canManage && (
          <button className="link" onClick={async () => {
            const title = (window.prompt("Test nomi") ?? "").trim();
            if (!title) return;
            const r = await call("POST", `courses/${courseId}/quizzes`, { title });
            if (r.ok) router.push(`/dashboard/quizzes/${r.data.quiz.id}/edit`); else setErr(errorText(r.data));
          }}>+ test</button>
        )}
      </div>
      <ul className="plain">
        {quizzes.map((q) => (
          <li key={q.id}>
            <Link className="a" href={`/dashboard/quizzes/${q.id}${canManage ? "/edit" : ""}`}>{q.title}</Link>
            {q.is_final && <span className="tag">yakuniy</span>}
            {canManage && !q.published && <span className="tag">yashirin</span>}
            <span className="muted"> · {q.questions_count} savol</span>
            {!canManage && q.my_attempts > 0 && (q.my_passed ? <span className="tag ok">o&apos;tdi · {q.my_best_percent}%</span> : <span className="tag warn">{q.my_best_percent}%</span>)}
          </li>
        ))}
        {quizzes.length === 0 && <li className="muted">Testlar yo&apos;q.</li>}
      </ul>
      {err && <p className="error">{err}</p>}
    </div>
  );
}
