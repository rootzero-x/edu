import { notFound } from "next/navigation";
import { api } from "@/lib/api";
import QuizEditor from "./QuizEditor";

export default async function EditQuizPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [q, r] = await Promise.all([api<any>(`/v1/quizzes/${id}`), api<any>(`/v1/quizzes/${id}/results`)]);
  if (q.status !== 200 || !q.data.quiz.questions) notFound();
  return <QuizEditor quiz={q.data.quiz} results={r.status === 200 ? r.data.data : []} />;
}
