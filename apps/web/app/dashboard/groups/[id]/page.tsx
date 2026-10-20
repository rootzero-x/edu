import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import type { GroupDetail, ReportRow, SessionRow } from "@/lib/types";
import { sessionsPath } from "@/lib/groups";
import GroupView from "./GroupView";
import Certificates from "./Certificates";

export async function generateMetadata({ params }: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const { id } = await params;
  const g = await api<{ group: GroupDetail }>(`/v1/groups/${id}`);
  return { title: g.status === 200 ? g.data.group.name : "Guruh" };
}

export default async function GroupPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [user, g, sessions, report] = await Promise.all([
    me(),
    api<{ group: GroupDetail }>(`/v1/groups/${id}`),
    api<{ data: SessionRow[] }>(`/v1/${sessionsPath(Number(id))}`),
    api<{ data: ReportRow[] }>(`/v1/groups/${id}/attendance`),
  ]);
  if (g.status !== 200) notFound();
  const manage = Boolean(user?.permissions.includes("group.manage"));
  return (
    <>
    <GroupView
      initial={{ group: g.data.group, sessions: sessions.data.data ?? [], report: report.data.data ?? [] }}
      manage={Boolean(user?.permissions.includes("group.manage"))}
      canMark={Boolean(user?.permissions.includes("attendance.mark"))}
      seeAll={Boolean(user?.permissions.includes("attendance.mark"))}
    />
    {manage && <Certificates groupId={g.data.group.id} />}
    </>
  );
}
