import { notFound } from "next/navigation";
import { api, me } from "@/lib/api";
import CourseEditor, { type Course } from "./CourseEditor";

export default async function CoursePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [user, res] = await Promise.all([me(), api<{ course: Course }>(`/v1/courses/${id}`)]);
  if (res.status !== 200) notFound();
  return <CourseEditor initial={res.data.course} canManage={Boolean(user?.permissions.includes("course.manage"))} />;
}
