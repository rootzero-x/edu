import type { Metadata } from "next";
import { BookOpen, Layers } from "lucide-react";
import { api, me, publicSettings } from "@/lib/api";
import { str } from "@/lib/settings";
import { fmtMoney } from "@/lib/format";
import { Badge, Card, EmptyState, PageHeader } from "@/components/ui";
import Link from "next/link";
import NewCourse from "./NewCourse";
import s from "./courses.module.css";

export const metadata: Metadata = { title: "Kurslar" };

type Course = { id: number; title: string; description: string | null; cover_url: string | null; published: boolean; monthly_fee: number; duration_months: number; modules_count: number };

export default async function CoursesPage() {
  const [user, st, { data }] = await Promise.all([me(), publicSettings(), api<{ data: Course[] }>("/v1/courses")]);
  const currency = str(st, "site.currency");
  const manage = Boolean(user?.permissions.includes("course.manage"));
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
              <div className={s.cover} aria-hidden>{c.cover_url ? <img src={c.cover_url} alt="" /> : <BookOpen />}</div>
              <div className={s.body}>
                <div className={s.titleRow}>
                  <h2>{c.title}</h2>
                  {!c.published && <Badge tone="warning">Yashirin</Badge>}
                </div>
                {c.description && <p>{c.description}</p>}
                <div className={s.meta}>
                  <span><Layers aria-hidden /> {c.modules_count} modul</span>
                  {c.monthly_fee > 0 && <strong>{fmtMoney(c.monthly_fee, currency)}</strong>}
                </div>
              </div>
            </Link>
          ))}
        </div>
      )}
    </>
  );
}
