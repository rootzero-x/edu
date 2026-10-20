import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import SettingsEditor, { type Item } from "./SettingsEditor";

export const metadata: Metadata = { title: "Sozlamalar" };

export default async function SettingsPage() {
  const user = await me();
  if (!user?.permissions.includes("settings.manage")) redirect("/dashboard");
  const { data } = await api<{
    data: Item[]; permissions: string[]; groups?: Record<string, string>; permission_labels?: Record<string, string>;
    admin_only_permissions?: string[]; show_keys?: boolean;
  }>("/v1/settings");
  return (
    <SettingsEditor items={data.data ?? []} permissions={data.permissions ?? []} groupLabels={data.groups ?? {}}
      permissionLabels={data.permission_labels ?? {}} adminOnly={data.admin_only_permissions ?? []} showKeys={Boolean(data.show_keys)} />
  );
}
