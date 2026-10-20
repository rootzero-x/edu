import { ArrowLeft, Lock, Send } from "lucide-react";
import { ButtonLink, Card, EmptyState, buttonClass } from "@/components/ui";

/**
 * API'ning tushunarli rad javoblari (matnlar sozlamalardan: course.*, quiz.*):
 * COURSE_ACCESS_DENIED (403, kursga yozilmagan), COURSE_UNPUBLISHED (404, kurs e'lon qilinmagan),
 * LESSON_UNPUBLISHED / QUIZ_UNPUBLISHED (404, dars/test yopiq — kursga qaytish tugmasi).
 */
export type Denial = {
  code?: string;
  title?: string;
  message?: string;
  button?: string;
  contact?: string;
  contact_url?: string;
  course?: { id: number; title: string };
};

export const DENIAL_CODES = ["COURSE_ACCESS_DENIED", "COURSE_UNPUBLISHED", "LESSON_UNPUBLISHED", "QUIZ_UNPUBLISHED"];

export function isDenial(data: unknown): data is Denial {
  return Boolean(data && typeof data === "object" && DENIAL_CODES.includes(String((data as Denial).code)));
}

/** Dars/test yopiq bo'lsa — kurs sahifasiga qaytish taklif qilinadi (kurs o'zi ochiq). */
export function backToCourse(d: Denial): boolean {
  return Boolean(d.course && (d.code === "LESSON_UNPUBLISHED" || d.code === "QUIZ_UNPUBLISHED"));
}

/** Kursga yozilmagan foydalanuvchi uchun: kurs bor, lekin ichiga kirish uchun murojaat qilish kerak. */
export default function AccessDenied({ denial, compact, action }: { denial: Denial; compact?: boolean; action?: React.ReactNode }) {
  const url = denial.contact_url || (denial.contact?.startsWith("@") ? `https://t.me/${denial.contact.slice(1)}` : undefined);
  return (
    <Card>
      <EmptyState
        compact={compact}
        icon={<Lock />}
        title={denial.title ?? "Ruxsat yo'q"}
        action={
          <>
            {url && (
              <a className={buttonClass({ variant: "primary" })} href={url} target="_blank" rel="noreferrer">
                <Send aria-hidden /> {denial.button || denial.contact}
              </a>
            )}
            {backToCourse(denial) && (
              <ButtonLink href={`/dashboard/courses/${denial.course!.id}`} icon={<ArrowLeft />}>{denial.course!.title}</ButtonLink>
            )}
            {action}
          </>
        }
      >
        {denial.message}
      </EmptyState>
    </Card>
  );
}
