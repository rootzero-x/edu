import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import ReportsView, { type Refs } from "./ReportsView";

export const metadata: Metadata = { title: "Hisobotlar" };

export default async function ReportsPage() {
  const user = await me();
  if (!user?.permissions.includes("report.view")) redirect("/dashboard");
  const manage = user.permissions.includes("group.manage");
  const [groups, courses, branches, teachers] = await Promise.all([
    api<{ data: { id: number; name: string; course_id: number; branch_id: number; teacher_id: number }[] }>("/v1/groups"),
    api<{ data: { id: number; title: string }[] }>("/v1/courses"),
    api<{ data: { id: number; name: string }[] }>("/v1/branches"),
    manage && user.permissions.includes("user.manage") ? api<{ data: { id: number; first_name: string; last_name: string | null }[] }>("/v1/users?role=TEACHER&active=1&per_page=500") : Promise.resolve(null),
  ]);
  const refs: Refs = {
    groups: groups.data.data ?? [],
    courses: courses.data.data ?? [],
    branches: branches.data.data ?? [],
    teachers: teachers?.data.data ?? [],
  };
  return <ReportsView refs={refs} />;
}
