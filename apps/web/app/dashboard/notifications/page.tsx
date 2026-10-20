import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { me } from "@/lib/api";
import NotificationsView from "./NotificationsView";

export const metadata: Metadata = { title: "Bildirishnomalar" };

export default async function NotificationsPage({ searchParams }: { searchParams: Promise<{ tab?: string }> }) {
  const user = await me();
  if (!user) redirect("/login");
  const admin = user.permissions.includes("user.manage");
  const sp = await searchParams;
  return <NotificationsView admin={admin} initialTab={admin && sp.tab === "report" ? "report" : "inbox"} />;
}
