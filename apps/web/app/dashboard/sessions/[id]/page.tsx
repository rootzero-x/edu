import Link from "next/link";
import { notFound } from "next/navigation";
import { api, me, publicSettings } from "@/lib/api";
import { fmtDateTime } from "@/lib/format";
import Sheet from "./Sheet";

export default async function SessionPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [user, res, s] = await Promise.all([me(), api<any>(`/v1/sessions/${id}/attendance`), publicSettings()]);
  if (res.status !== 200) notFound();
  const { session, group, rows } = res.data;
  return (
    <>
      <p><Link className="a" href={`/dashboard/groups/${group.id}`}>← {group.name}</Link></p>
      <h1>{fmtDateTime(session.starts_at, String(s?.["app.timezone"] ?? "Asia/Tashkent"))} {session.cancelled && <span className="tag">bekor qilingan</span>}</h1>
      <Sheet
        sessionId={session.id}
        rows={rows}
        topic={session.topic ?? ""}
        canMark={Boolean(user?.permissions.includes("attendance.mark")) && !session.cancelled}
        labels={(s?.["attendance.status_labels"] ?? {}) as Record<string, string>}
      />
    </>
  );
}
