import { redirect } from "next/navigation";
import { api, me } from "@/lib/api";
import Users, { type UserRow } from "./Users";

export default async function UsersPage({ searchParams }: { searchParams: Promise<{ role?: string; q?: string }> }) {
  const user = await me();
  if (!user?.permissions.includes("user.manage")) redirect("/dashboard");
  const sp = await searchParams;
  const qs = new URLSearchParams(Object.entries(sp).filter(([, v]) => v) as [string, string][]).toString();
  const { data } = await api<{ data: UserRow[]; meta: { total: number } }>(`/v1/users${qs ? `?${qs}` : ""}`);
  return <Users rows={data.data ?? []} total={data.meta?.total ?? 0} actorRole={user.role} selfId={user.id} />;
}
