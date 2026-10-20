import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import SettingsEditor, { type Item } from "./SettingsEditor";

export const metadata: Metadata = { title: "Sozlamalar" };

export default async function SettingsPage({ searchParams }: { searchParams: Promise<{ group?: string }> }) {
  const [user, sp] = await Promise.all([me(), searchParams]);
  if (!user?.permissions.includes("settings.manage")) redirect("/dashboard");
  const { data } = await api<{
    data: Item[]; permissions: string[]; permission_labels?: Record<string, string>; groups?: Record<string, string>; warnings?: string[];
    admin_only_permissions?: string[];
  }>("/v1/settings");
  return (
    <SettingsEditor items={data.data ?? []} permissions={data.permissions ?? []} permissionLabels={data.permission_labels ?? {}}
      groupLabels={data.groups ?? {}} warnings={data.warnings ?? []} showKeys={user.role === "SUPER_ADMIN"} initialGroup={sp.group}
      adminOnly={data.admin_only_permissions ?? []} />
  );
}
