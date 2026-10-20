import type { Metadata } from "next";
import NotFoundCard from "@/components/NotFoundCard";
import { api } from "@/lib/api";
import type { Assignment, Scale } from "@/lib/homework";
import HomeworkDetail from "./HomeworkDetail";

type Resp = { assignment: Assignment; scale: Scale };

export async function generateMetadata({ params }: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const { id } = await params;
  const r = await api<Resp>(`/v1/assignments/${id}`);
  return { title: r.status === 200 ? r.data.assignment.title : "Uyga vazifa" };
}

export default async function HomeworkPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const r = await api<Resp>(`/v1/assignments/${id}`);
  if (r.status !== 200) return <NotFoundCard />;
  return <HomeworkDetail initial={r.data.assignment} />;
}
