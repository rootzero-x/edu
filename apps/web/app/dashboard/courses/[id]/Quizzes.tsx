"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { ClipboardCheck, Lock, Plus } from "lucide-react";
import { Badge, Button, Card, CardHeader, EmptyState, usePrompt, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";
import s from "./editor.module.css";

type Quiz = {
  id: number; title: string; is_final: boolean; published: boolean; questions_count: number;
  locked?: boolean; my_attempts?: number; my_best_percent?: number | null; my_passed?: boolean;
};

export default function Quizzes({ courseId, quizzes, canManage }: { courseId: number; quizzes: Quiz[]; canManage: boolean }) {
  const router = useRouter();
  const prompt = usePrompt();
  const toast = useToast();

  const add = () => prompt({
    title: "Yangi test",
    fields: [{ name: "title", label: "Test nomi", required: true }],
    submitText: "Yaratish",
    onSubmit: async (v) => {
      const r = await call("POST", `courses/${courseId}/quizzes`, { title: v.title });
      if (!r.ok) return errorText(r.data);
      toast.success("Test yaratildi", v.title);
      router.refresh(); // sahifalar keshi yangilansin
      router.push(`/dashboard/quizzes/${r.data.quiz.id}/edit`);
    },
  });

  return (
    <Card style={{ marginTop: 20 }}>
      <CardHeader title="Testlar" description={`${quizzes.length} ta`} actions={canManage && <Button size="sm" icon={<Plus />} onClick={add}>Test</Button>} />
      {quizzes.length === 0 ? (
        <EmptyState compact icon={<ClipboardCheck />} title="Testlar yo'q" />
      ) : (
        <ol className={s.lessons}>
          {quizzes.map((q) => (
            <li key={q.id} className={s.lesson}>
              <span className={s.lessonIcon} aria-hidden>{q.locked ? <Lock /> : <ClipboardCheck />}</span>
              <Link className={s.lessonTitle} href={`/dashboard/quizzes/${q.id}${canManage ? "/edit" : ""}`}>{q.title}</Link>
              <span className="subtle">{q.questions_count} savol</span>
              {q.is_final && <Badge tone="accent">Yakuniy</Badge>}
              {canManage && !q.published && <Badge tone="warning">Yashirin</Badge>}
              {!canManage && (q.my_attempts ?? 0) > 0 && (q.my_passed
                ? <Badge tone="success">O&apos;tdi · {q.my_best_percent}%</Badge>
                : <Badge tone="warning">{q.my_best_percent}%</Badge>)}
            </li>
          ))}
        </ol>
      )}
    </Card>
  );
}
