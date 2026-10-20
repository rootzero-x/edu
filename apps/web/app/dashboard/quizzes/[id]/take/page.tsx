import type { Metadata } from "next";
import TakeQuiz from "./TakeQuiz";

export const metadata: Metadata = { title: "Testni topshirish" };

export default async function TakePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <TakeQuiz quizId={Number(id)} />;
}
