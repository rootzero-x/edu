import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { me } from "@/lib/api";
import CalendarView from "./CalendarView";

export const metadata: Metadata = { title: "Taqvim" };

export default async function CalendarPage() {
  const user = await me();
  if (!user) redirect("/login");
  return (
    <CalendarView
      manage={user.permissions.includes("group.manage")}
      staff={user.permissions.includes("attendance.mark")}
    />
  );
}
