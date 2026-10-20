import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import { type Refs } from "./ReportsView";
import ReportsTabs from "./ReportsTabs";

export const metadata: Metadata = { title: "Hisobotlar" };

export default async function ReportsPage() {
  const user = await me();
  if (!user?.permissions.includes("report.view")) redirect("/dashboard");
  const manage = user.permissions.includes("group.manage");
  const [groups, courses, teachers] = await Promise.all([
    api<{ data: { id: number; name: string; course_id: number; teacher_id: number }[] }>("/v1/groups"),
    api<{ data: { id: number; title: string }[] }>("/v1/courses"),
    manage ? api<{ data: { id: number; first_name: string; last_name: string | null }[] }>("/v1/groups/teachers") : Promise.resolve(null),
  ]);
  const refs: Refs = {
    groups: groups.data.data ?? [],
    courses: courses.data.data ?? [],
    teachers: teachers?.data.data ?? [],
  };
  return <ReportsTabs refs={refs} />;
}
