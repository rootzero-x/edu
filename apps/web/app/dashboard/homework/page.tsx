import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { me } from "@/lib/api";
import HomeworkList from "./HomeworkList";

export const metadata: Metadata = { title: "Uyga vazifa" };

export default async function HomeworkPage({ searchParams }: { searchParams: Promise<{ tab?: string; group_id?: string }> }) {
  const user = await me();
  if (!user) redirect("/login");
  const sp = await searchParams;
  return <HomeworkList initialTab={sp.tab === "marks" ? "marks" : sp.tab === "all" ? "all" : "open"} initialGroup={sp.group_id ?? ""} />;
}
