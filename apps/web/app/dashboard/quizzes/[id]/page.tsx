import { redirect } from "next/navigation";
import NotFoundCard from "@/components/NotFoundCard";
import type { Metadata } from "next";
import { ClipboardCheck, Clock, RotateCcw, Target } from "lucide-react";
import { api, publicSettings } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import { timezone } from "@/lib/settings";
import AccessDenied, { isDenial, type Denial } from "@/components/AccessDenied";
import StartQuiz from "./StartQuiz";
import { Badge, Card, CardHeader, EmptyState, PageHeader, StatCard, Table, Td, Th } from "@/components/ui";

type Attempt = { id: number; submitted_at: string | null; score: number | null; max_score: number | null; percent: number | null; passed: boolean | null };
type Res = {
  quiz: { id: number; title: string; is_final: boolean; pass_percent: number; max_attempts: number; time_limit_sec: number | null; questions_count: number; course: { id: number; title: string } };
  attempts: Attempt[];
  can_take: boolean;
  can_start?: boolean;
};
// Kalitni ko'radigan xodimga API to'liq testni (savollar bilan) va can_edit ni qaytaradi.
type ManageRes = { quiz: { questions: unknown[] }; can_edit: boolean };

export const metadata: Metadata = { title: "Test" };

export default async function QuizPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [res, st] = await Promise.all([api<Res | Denial | ManageRes>(`/v1/quizzes/${id}`), publicSettings()]);
  // Tahrir sahifasiga faqat API kalitni bergan bo'lsa (muallif/admin/shu kurs o'qituvchisi); boshqa o'qituvchi — o'qish ko'rinishi.
  if (res.status === 200 && ("can_edit" in res.data || Array.isArray((res.data as ManageRes).quiz?.questions))) {
    redirect(`/dashboard/quizzes/${id}/edit`);
  }
  if (res.status === 403 && isDenial(res.data)) {
    const d = res.data;
    return (
      <>
        <PageHeader back={d.course ? { href: `/dashboard/courses/${d.course.id}`, label: d.course.title } : undefined} title="Test" />
        <AccessDenied denial={d} />
      </>
    );
  }
  if (res.status !== 200) return <NotFoundCard />;
  const { quiz, attempts, can_take } = res.data as Res;
  const tz = timezone(st);
  const done = attempts.filter((a) => a.submitted_at);
  const open = attempts.find((a) => !a.submitted_at);
  const best = done.reduce<number | null>((m, a) => (a.percent !== null && (m === null || a.percent > m) ? a.percent : m), null);
  const left = Math.max(0, quiz.max_attempts - done.length);
  const passed = done.some((a) => a.passed);
  // can_start: limit va "o'tgan testni qayta topshirish" sozlamasi serverda hisoblangan (eski API — o'zimiz hisoblaymiz).
  const canStart = (res.data as Res).can_start ?? (can_take && (Boolean(open) || (left > 0 && !passed)));

  return (
    <>
      <PageHeader
        back={{ href: `/dashboard/courses/${quiz.course.id}`, label: quiz.course.title }}
        title={quiz.title}
        badge={quiz.is_final ? <Badge tone="accent">Yakuniy test</Badge> : undefined}
        actions={canStart
          ? <StartQuiz quizId={quiz.id} resume={Boolean(open)} left={left} minutes={quiz.time_limit_sec ? Math.round(quiz.time_limit_sec / 60) : null} />
          : undefined}
      />
      <div className="stats-grid">
        <StatCard label="Savollar" value={quiz.questions_count} icon={<ClipboardCheck />} />
        <StatCard label="O'tish bali" value={`${quiz.pass_percent}%`} icon={<Target />} />
        {can_take && <StatCard label="Qolgan urinish" value={passed && !canStart ? "O'tdingiz" : `${left}/${quiz.max_attempts}`} icon={<RotateCcw />} tone={passed ? "success" : left ? "accent" : "warning"} />}
        <StatCard label="Vaqt" value={quiz.time_limit_sec ? `${Math.round(quiz.time_limit_sec / 60)} daq.` : "Cheksiz"} icon={<Clock />} />
      </div>
      {!can_take && <Card><EmptyState compact icon={<ClipboardCheck />} title="Bu testni faqat kursga yozilgan o'quvchilar topshiradi" /></Card>}
      {(can_take || done.length > 0) && <Card style={{ marginTop: 16 }}>
        <CardHeader title="Natijalarim" description={best !== null ? `Eng yaxshi natija: ${best}%` : undefined} />
        {done.length === 0 ? (
          <EmptyState compact icon={<Target />} title="Hali topshirilmagan" />
        ) : (
          <Table stack>
            <thead><tr><Th>Sana</Th><Th>Ball</Th><Th>%</Th><Th>Natija</Th></tr></thead>
            <tbody>
              {done.map((a) => (
                <tr key={a.id}>
                  <Td label="Sana">{fmtDateTime(a.submitted_at!, tz)}</Td>
                  <Td label="Ball">{a.score}/{a.max_score}</Td>
                  <Td label="%">{a.percent}</Td>
                  <Td label="Natija">{a.passed ? <Badge tone="success">O&apos;tdi</Badge> : <Badge tone="warning">O&apos;tmadi</Badge>}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>}
    </>
  );
}
