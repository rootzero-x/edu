import { notFound, redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import type { StudentBilling } from "@/lib/billing";
import StudentBillingView from "./StudentBillingView";

export const metadata: Metadata = { title: "To'lovlar" };

export default async function StudentBillingPage({ params, searchParams }: {
  params: Promise<{ id: string }>; searchParams: Promise<{ type?: string; page?: string }>;
}) {
  const [{ id }, sp, user] = await Promise.all([params, searchParams, me()]);
  if (!user) redirect("/login");
  if (!/^\d+$/.test(id)) notFound();
  const qs = new URLSearchParams(Object.entries(sp).filter(([, v]) => typeof v === "string" && v) as [string, string][]).toString();
  const r = await api<StudentBilling>(`/v1/billing/students/${id}${qs ? `?${qs}` : ""}`);
  if (r.status === 403) redirect("/dashboard");
  if (r.status !== 200) notFound();
  return <StudentBillingView data={r.data} type={sp.type ?? ""} />;
}
