import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import type { LeadList, LeadOptions } from "@/lib/leads";
import LeadsView from "./LeadsView";

export const metadata: Metadata = { title: "Arizalar" };

export default async function LeadsPage({ searchParams }: { searchParams: Promise<{ id?: string }> }) {
  const [user, sp] = await Promise.all([me(), searchParams]);
  if (!user?.permissions.includes("lead.manage")) redirect("/dashboard");
  const [list, options] = await Promise.all([
    api<LeadList>("/v1/leads?status=NEW,CONTACTED,TRIAL"),
    api<LeadOptions>("/v1/leads/options"),
  ]);
  return (
    <LeadsView
      initial={list.status === 200 ? list.data : null}
      options={options.status === 200 ? options.data : { data: [], courses: [] }}
      openId={sp.id && /^\d+$/.test(sp.id) ? Number(sp.id) : null}
    />
  );
}
