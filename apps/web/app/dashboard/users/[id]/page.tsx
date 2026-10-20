import { notFound, redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import ProfileView, { type ProfileData } from "./ProfileView";

export const metadata: Metadata = { title: "O'quvchi profili" };

export default async function UserProfilePage({ params }: { params: Promise<{ id: string }> }) {
  const [{ id }, user] = await Promise.all([params, me()]);
  if (!user) redirect("/login");
  if (!/^\d+$/.test(id)) notFound();
  const r = await api<ProfileData>(`/v1/users/${id}/profile`);
  if (r.status === 403) redirect("/dashboard");
  if (r.status !== 200) notFound();
  return <ProfileView data={r.data} canBilling={user.permissions.includes("billing.manage")} canUsers={user.permissions.includes("user.manage")} canLeads={user.permissions.includes("lead.manage")} />;
}
