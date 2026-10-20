import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import Sheet, { type SheetData } from "./Sheet";

export const metadata: Metadata = { title: "Davomat" };

export default async function SessionPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [user, res] = await Promise.all([me(), api<SheetData>(`/v1/sessions/${id}/attendance`)]);
  if (res.status !== 200) notFound();
  return <Sheet initial={res.data} canMark={Boolean(user?.permissions.includes("attendance.mark"))} />;
}
