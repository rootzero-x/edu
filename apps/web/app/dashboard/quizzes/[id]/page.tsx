import { notFound, redirect } from "next/navigation";
import type { Metadata } from "next";
import { ClipboardCheck, Clock, RotateCcw, Target } from "lucide-react";
import { api, me, publicSettings } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import { timezone } from "@/lib/settings";
import AccessDenied, { isDenial, type Denial } from "@/components/AccessDenied";
import { Badge, ButtonLink, Card, CardHeader, EmptyState, PageHeader, StatCard, Table, Td, Th } from "@/components/ui";

type Attempt = { id: number; submitted_at: string | null; score: number | null; max_score: number | null; percent: number | null; passed: boolean | null };
type Res = {
  quiz: { id: number; title: string; is_final: boolean; pass_percent: number; max_attempts: number; time_limit_sec: number | null; questions_count: number; course: { id: number; title: string } };
  attempts: Attempt[];
  can_take: boolean;
};

export const metadata: Metadata = { title: "Test" };

export default async function QuizPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const user = await me();
  if (user?.permissions.includes("quiz.manage")) redirect(`/dashboard/quizzes/${id}/edit`);
  const [res, st] = await Promise.all([api<Res | Denial>(`/v1/quizzes/${id}`), publicSettings()]);
  if (res.status === 403 && isDenial(res.data)) {
    const d = res.data;
    return (
      <>
        <PageHeader back={d.course ? { href: `/dashboard/courses/${d.course.id}`, label: d.course.title } : undefined} title="Test" />
        <AccessDenied denial={d} />
      </>
    );
  }
  if (res.status !== 200) notFound();
  const { quiz, attempts, can_take } = res.data as Res;
  const tz = timezone(st);
  const done = attempts.filter((a) => a.submitted_at);
  const open = attempts.find((a) => !a.submitted_at);
  const best = done.reduce<number | null>((m, a) => (a.percent !== null && (m === null || a.percent > m) ? a.percent : m), null);
  const left = Math.max(0, quiz.max_attempts - done.length);

  return (
    <>
      <PageHeader
        back={{ href: `/dashboard/courses/${quiz.course.id}`, label: quiz.course.title }}
        title={quiz.title}
        badge={quiz.is_final ? <Badge tone="accent">Yakuniy test</Badge> : undefined}
        actions={can_take && (open || left > 0)
          ? <ButtonLink href={`/dashboard/quizzes/${id}/take`} icon={<ClipboardCheck />}>{open ? "Davom ettirish" : "Testni boshlash"}</ButtonLink>
          : undefined}
      />
      <div className="stats-grid">
        <StatCard label="Savollar" value={quiz.questions_count} icon={<ClipboardCheck />} />
        <StatCard label="O'tish bali" value={`${quiz.pass_percent}%`} icon={<Target />} />
        <StatCard label="Qolgan urinish" value={`${left}/${quiz.max_attempts}`} icon={<RotateCcw />} tone={left ? "accent" : "warning"} />
        <StatCard label="Vaqt" value={quiz.time_limit_sec ? `${Math.round(quiz.time_limit_sec / 60)} daq.` : "Cheksiz"} icon={<Clock />} />
      </div>
      {!can_take && <Card><EmptyState compact icon={<ClipboardCheck />} title="Bu testni faqat kursga yozilgan o'quvchilar topshiradi" /></Card>}
      <Card style={{ marginTop: 16 }}>
        <CardHeader title="Natijalarim" description={best !== null ? `Eng yaxshi natija: ${best}%` : undefined} />
        {done.length === 0 ? (
          <EmptyState compact icon={<Target />} title="Hali topshirilmagan" />
        ) : (
          <Table>
            <thead><tr><Th>Sana</Th><Th>Ball</Th><Th>%</Th><Th>Natija</Th></tr></thead>
            <tbody>
              {done.map((a) => (
                <tr key={a.id}>
                  <Td>{fmtDateTime(a.submitted_at!, tz)}</Td>
                  <Td>{a.score}/{a.max_score}</Td>
                  <Td>{a.percent}</Td>
                  <Td>{a.passed ? <Badge tone="success">O&apos;tdi</Badge> : <Badge tone="warning">O&apos;tmadi</Badge>}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}
