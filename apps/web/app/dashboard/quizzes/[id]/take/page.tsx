import TakeQuiz from "./TakeQuiz";

export default async function TakePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <TakeQuiz quizId={Number(id)} />;
}
