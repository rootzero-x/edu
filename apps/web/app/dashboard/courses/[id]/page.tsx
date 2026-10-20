import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import CourseEditor, { type Course } from "./CourseEditor";

export async function generateMetadata({ params }: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const { id } = await params;
  const res = await api<{ course: Course }>(`/v1/courses/${id}`);
  return { title: res.status === 200 ? res.data.course.title : "Kurs" };
}

export default async function CoursePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [user, res] = await Promise.all([me(), api<{ course: Course }>(`/v1/courses/${id}`)]);
  if (res.status !== 200) notFound();
  return <CourseEditor initial={res.data.course} canManage={Boolean(user?.permissions.includes("course.manage"))} />;
}
