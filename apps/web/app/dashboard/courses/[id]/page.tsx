import type { Metadata } from "next";
import NotFoundCard from "@/components/NotFoundCard";
import AccessDenied, { isDenial } from "@/components/AccessDenied";
import { PageHeader } from "@/components/ui";
import { api, me } from "@/lib/api";
import CourseEditor, { type Course } from "./CourseEditor";
import Quizzes from "./Quizzes";

export async function generateMetadata({ params }: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const { id } = await params;
  const res = await api<{ course: Course }>(`/v1/courses/${id}`);
  return { title: res.status === 200 ? res.data.course.title : "Kurs" };
}

export default async function CoursePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [user, res, qz] = await Promise.all([me(), api<{ course: Course; access?: any; can_edit?: boolean }>(`/v1/courses/${id}`), api<{ data: any[] }>(`/v1/courses/${id}/quizzes`)]);
  // E'lon qilinmagan kurs va unga aloqasi yo'q foydalanuvchi — sozlamadagi tushunarli xabar.
  const raw: unknown = res.data;
  if (isDenial(raw)) {
    return (
      <>
        <PageHeader back={{ href: "/dashboard/courses", label: "Kurslar" }} title={raw.course?.title ?? "Kurs"} />
        <AccessDenied denial={raw} />
      </>
    );
  }
  if (res.status !== 200) return <NotFoundCard message={(res.data as { message?: string })?.message} />;
  return (
    <>
      <CourseEditor initial={res.data.course} canManage={Boolean(res.data.can_edit)} access={res.data.access} />
      <Quizzes courseId={res.data.course.id} quizzes={qz.data.data ?? []} canManage={Boolean(res.data.can_edit && user?.permissions.includes("quiz.manage"))} />
    </>
  );
}
