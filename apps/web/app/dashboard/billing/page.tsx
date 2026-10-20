import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import BillingView, { type BalanceRow, type BalancesSummary } from "./BillingView";

export const metadata: Metadata = { title: "To'lovlar" };

type SP = { q?: string; status?: string; group_id?: string; enrolled?: string; sort?: string; page?: string };

export default async function BillingPage({ searchParams }: { searchParams: Promise<SP> }) {
  const user = await me();
  if (!user?.permissions.includes("billing.manage")) redirect("/dashboard");
  const sp = await searchParams;
  const qs = new URLSearchParams(Object.entries(sp).filter(([, v]) => typeof v === "string" && v) as [string, string][]).toString();
  const [list, groups] = await Promise.all([
    api<{ data: BalanceRow[]; meta: { total: number; page: number; pages: number }; summary: BalancesSummary; message?: string }>(`/v1/billing/balances${qs ? `?${qs}` : ""}`),
    api<{ data: { id: number; name: string; active: boolean }[] }>("/v1/groups"),
  ]);
  return (
    <BillingView
      rows={list.status === 200 ? list.data.data : []}
      meta={list.data.meta ?? { total: 0, page: 1, pages: 1 }}
      summary={list.data.summary ?? null}
      error={list.status === 200 ? null : list.data.message ?? "Ma'lumotni yuklab bo'lmadi."}
      filters={{ q: sp.q ?? "", status: sp.status ?? "all", group_id: sp.group_id ?? "", enrolled: sp.enrolled ?? "", sort: sp.sort ?? "" }}
      groups={(groups.data.data ?? []).map((g) => ({ id: g.id, name: g.name }))}
    />
  );
}
