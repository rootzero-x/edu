import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { me } from "@/lib/api";
import AuditView from "./AuditView";

export const metadata: Metadata = { title: "Audit jurnali" };

export default async function AuditPage({ searchParams }: { searchParams: Promise<Record<string, string | undefined>> }) {
  const user = await me();
  if (!user?.permissions.includes("audit.view")) redirect("/dashboard");
  const sp = await searchParams;
  return <AuditView initial={{ actor_id: sp.actor_id ?? "", entity: sp.entity ?? "", entity_id: sp.entity_id ?? "", action: sp.action ?? "", from: sp.from ?? "", to: sp.to ?? "" }} />;
}
