import type { Metadata } from "next";
import { BookOpen, Layers } from "lucide-react";
import { api, me, publicSettings } from "@/lib/api";
import { str } from "@/lib/settings";
import { Badge, Card, EmptyState, PageHeader, SafeImage } from "@/components/ui";
import Link from "next/link";
import NewCourse from "./NewCourse";
import s from "./courses.module.css";

export const metadata: Metadata = { title: "Kurslar" };

type Course = { id: number; title: string; description: string | null; cover_url: string | null; published: boolean; monthly_fee: number; duration_months: number; modules_count: number };

export default async function CoursesPage() {
  const [user, st, { data }] = await Promise.all([me(), publicSettings(), api<{ data: Course[] }>("/v1/courses")]);
  // Kurs yaratish: hamma kurslarni boshqaruvchi (course.manage) yoki o'z kurslari muallifi (course.author — o'qituvchi).
  const manage = Boolean(user?.permissions.some((p) => p === "course.manage" || p === "course.author"));
  const list = data.data ?? [];
  return (
    <>
      <PageHeader title="Kurslar" description={`${list.length} ta kurs`} actions={manage && <NewCourse />} />
      {list.length === 0 ? (
        <Card><EmptyState icon={<BookOpen />} title="Hali kurs yo'q" action={manage && <NewCourse />}>Birinchi kursni yarating — modullar va darslarni keyin qo&apos;shasiz.</EmptyState></Card>
      ) : (
        <div className={s.grid}>
          {list.map((c) => (
            <Link href={`/dashboard/courses/${c.id}`} key={c.id} className={s.card}>
              <div className={s.cover} aria-hidden><SafeImage src={c.cover_url} fallback={<BookOpen />} /></div>
              <div className={s.body}>
                <div className={s.titleRow}>
                  <h2>{c.title}</h2>
                  {!c.published && <Badge tone="warning">Yashirin</Badge>}
                </div>
                {c.description && <p>{c.description}</p>}
                <div className={s.meta}>
                  <span><Layers aria-hidden /> {c.modules_count} modul</span>
                </div>
              </div>
            </Link>
          ))}
        </div>
      )}
    </>
  );
}
