import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import type { ChildOverview } from "@/lib/family";
import ChildrenView from "./ChildrenView";

export const metadata: Metadata = { title: "Farzandlarim" };

export default async function ChildrenPage() {
  const user = await me();
  if (!user) redirect("/login");
  if (user.role !== "PARENT") redirect("/dashboard");
  const r = await api<{ data: ChildOverview[] }>("/v1/me/children");
  return <ChildrenView initial={r.status === 200 ? r.data.data ?? [] : null} />;
}
