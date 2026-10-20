import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { api, me } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import { publicSettings } from "@/lib/api";

export default async function QuizPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const user = await me();
  if (user?.permissions.includes("quiz.manage")) redirect(`/dashboard/quizzes/${id}/edit`);
  const [res, s] = await Promise.all([api<any>(`/v1/quizzes/${id}`), publicSettings()]);
  if (res.status !== 200) notFound();
  const { quiz, attempts, can_take } = res.data;
  const tz = String(s?.["app.timezone"] ?? "Asia/Tashkent");
  const used = attempts.filter((a: any) => a.submitted_at).length;
  const open = attempts.find((a: any) => !a.submitted_at);
  return (
    <>
      <p><Link className="a" href={`/dashboard/courses/${quiz.course.id}`}>← {quiz.course.title}</Link></p>
      <h1>{quiz.title} {quiz.is_final && <span className="tag">yakuniy</span>}</h1>
      <div className="card">
        <p>{quiz.questions_count} ta savol · o&apos;tish bali {quiz.pass_percent}% · urinishlar {used}/{quiz.max_attempts}
          {quiz.time_limit_sec ? ` · vaqt ${Math.round(quiz.time_limit_sec / 60)} daqiqa` : ""}</p>
        {can_take && (open || used < quiz.max_attempts)
          ? <Link className="btn" href={`/dashboard/quizzes/${id}/take`}>{open ? "Davom ettirish" : "Testni boshlash"}</Link>
          : <p className="muted">{can_take ? "Urinishlar tugagan." : "Bu test sizning guruhingiz kursiga tegishli emas."}</p>}
      </div>
      {used > 0 && (
        <table>
          <thead><tr><th>Sana</th><th>Ball</th><th>%</th><th>Natija</th></tr></thead>
          <tbody>
            {attempts.filter((a: any) => a.submitted_at).map((a: any) => (
              <tr key={a.id}><td>{fmtDateTime(a.submitted_at, tz)}</td><td>{a.score}/{a.max_score}</td><td>{a.percent}</td>
                <td>{a.passed ? <span className="tag ok">o&apos;tdi</span> : <span className="tag warn">o&apos;tmadi</span>}</td></tr>
            ))}
          </tbody>
        </table>
      )}
    </>
  );
}
