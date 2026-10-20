"use client";

import { useRouter } from "next/navigation";
import { ClipboardCheck } from "lucide-react";
import { Button, useConfirm } from "@/components/ui";

/** Testni boshlash: tasodifan bosilib urinish yo'qolmasligi uchun avval tasdiqlash (ochiq urinishda — darhol davom). */
export default function StartQuiz({ quizId, resume, left, minutes }: { quizId: number; resume: boolean; left: number; minutes: number | null }) {
  const router = useRouter();
  const confirm = useConfirm();
  return (
    <Button icon={<ClipboardCheck />} onClick={async () => {
      if (!resume && !(await confirm({
        title: "Testni boshlaysizmi?",
        description: `${minutes ? `Vaqt: ${minutes} daqiqa — boshlangan zahoti hisoblanadi. ` : ""}Bu ${left} ta qolgan urinishingizdan biri hisoblanadi.`,
        confirmText: "Boshlash",
      }))) return;
      router.push(`/dashboard/quizzes/${quizId}/take`);
    }}>{resume ? "Davom ettirish" : "Testni boshlash"}</Button>
  );
}
