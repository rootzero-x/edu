import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import Users, { type BranchOpt, type UserRow } from "./Users";

export const metadata: Metadata = { title: "Foydalanuvchilar" };

export default async function UsersPage({ searchParams }: { searchParams: Promise<{ role?: string; q?: string; page?: string }> }) {
  const user = await me();
  if (!user?.permissions.includes("user.manage")) redirect("/dashboard");
  const sp = await searchParams;
  const qs = new URLSearchParams(Object.entries(sp).filter(([, v]) => v) as [string, string][]).toString();
  const [{ data }, br] = await Promise.all([
    api<{ data: UserRow[]; meta: { total: number; page: number; pages: number } }>(`/v1/users${qs ? `?${qs}` : ""}`),
    api<{ data: BranchOpt[] }>("/v1/branches"),
  ]);
  return (
    <Users
      rows={data.data ?? []}
      meta={data.meta ?? { total: 0, page: 1, pages: 1 }}
      role={sp.role ?? ""}
      q={sp.q ?? ""}
      actorRole={user.role}
      selfId={user.id}
      branches={(br.data.data ?? []).filter((b: BranchOpt & { active?: boolean }) => b.active !== false)}
    />
  );
}
