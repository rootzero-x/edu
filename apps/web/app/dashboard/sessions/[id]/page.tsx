import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { Lock } from "lucide-react";
import { api, me } from "@/lib/api";
import { Card, EmptyState, PageHeader } from "@/components/ui";
import Sheet, { type SheetData } from "./Sheet";

export const metadata: Metadata = { title: "Davomat" };

export default async function SessionPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [user, res] = await Promise.all([me(), api<SheetData>(`/v1/sessions/${id}/attendance`)]);
  if (res.status === 403) {
    return (
      <>
        <PageHeader title="Davomat" back={{ href: "/dashboard/groups", label: "Guruhlar" }} />
        <Card><EmptyState icon={<Lock />} title="Ruxsat yo'q">{(res.data as { message?: string })?.message ?? "Bu dars davomatini ko'rish huquqingiz yo'q."}</EmptyState></Card>
      </>
    );
  }
  if (res.status !== 200) notFound();
  return <Sheet initial={res.data} canMark={Boolean(user?.permissions.includes("attendance.mark"))} />;
}
