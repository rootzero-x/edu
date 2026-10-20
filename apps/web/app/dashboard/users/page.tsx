import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me, publicSettings } from "@/lib/api";
import Users, { type UserRow } from "./Users";

export const metadata: Metadata = { title: "Foydalanuvchilar" };

/** Sahifa ko'rinishi → API filtri. */
const VIEW_PARAMS: Record<string, Record<string, string>> = { pending: { pending: "1" }, tg_none: { telegram: "none" }, tg_blocked: { telegram: "blocked" } };

export default async function UsersPage({ searchParams }: { searchParams: Promise<{ role?: string; q?: string; page?: string; view?: string }> }) {
  const user = await me();
  if (!user?.permissions.includes("user.manage")) redirect("/dashboard");
  const { view, ...sp } = await searchParams;
  const qs = new URLSearchParams({
    ...(Object.fromEntries(Object.entries(sp).filter(([, v]) => v)) as Record<string, string>),
    ...(VIEW_PARAMS[view ?? ""] ?? {}),
  }).toString();
  const [{ data }, settings] = await Promise.all([
    api<{ data: UserRow[]; meta: { total: number; page: number; pages: number; pending?: number } }>(`/v1/users${qs ? `?${qs}` : ""}`),
    publicSettings(),
  ]);
  return (
    <Users
      rows={data.data ?? []}
      meta={data.meta ?? { total: 0, page: 1, pages: 1 }}
      role={sp.role ?? ""}
      q={sp.q ?? ""}
      view={VIEW_PARAMS[view ?? ""] ? (view as string) : ""}
      phoneHint={String(settings?.["auth.phone_hint"] ?? "+998 90 123 45 67")}
      actorRole={user.role}
      selfId={user.id}
    />
  );
}
