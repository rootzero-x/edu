import { redirect } from "next/navigation";
import { api } from "@/lib/api";
import NotFoundCard from "@/components/NotFoundCard";
import QuizEditor from "./QuizEditor";

export const metadata = { title: "Testni tahrirlash" };

export default async function EditQuizPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [q, r] = await Promise.all([api<any>(`/v1/quizzes/${id}`), api<any>(`/v1/quizzes/${id}/results`)]);
  if (q.status !== 200) return <NotFoundCard />;
  // Kalitsiz javob (boshqa o'qituvchi) — tahrir emas, o'qish/o'quvchi ko'rinishi.
  if (!q.data.quiz?.questions) redirect(`/dashboard/quizzes/${id}`);
  return <QuizEditor quiz={q.data.quiz} readOnly={!q.data.can_edit} results={r.status === 200 ? r.data.data : []} />;
}
