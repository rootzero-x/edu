"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { NotebookPen, Plus } from "lucide-react";
import { Badge, Button, Card, CardHeader, EmptyState, Progress, Table, Td, Th } from "@/components/ui";
import AssignmentDialog from "@/components/homework/AssignmentDialog";
import { useSiteSettings } from "@/components/SiteProvider";
import { call } from "@/lib/client";
import { fmtDateTime } from "@/lib/format";
import { HW_DEFAULT_LABELS, HW_TONE, type Assignment } from "@/lib/homework";
import { obj, timezone } from "@/lib/settings";

/** Guruh sahifasidagi "Uyga vazifa" bo'limi: o'qituvchiga — topshirganlar soni, o'quvchiga — o'z holati. */
export default function GroupHomework({ groupId }: { groupId: number }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const labels = { ...HW_DEFAULT_LABELS, ...obj<Record<string, string>>(st, "homework.status_labels") };
  const [data, setData] = useState<{ mode: "teacher" | "student"; data: Assignment[] } | null>(null);
  const [creating, setCreating] = useState(false);
  const load = useCallback(async () => {
    const r = await call<{ mode: "teacher" | "student"; data: Assignment[] }>("GET", `assignments?group_id=${groupId}&status=all&limit=50`);
    if (r.ok) setData(r.data);
  }, [groupId]);
  useEffect(() => { void load(); }, [load]);
  const teacher = data?.mode === "teacher";

  return (
    <Card>
      <CardHeader title="Uyga vazifalar" description={data ? `${data.data.length} ta` : undefined}
        actions={teacher && <Button size="sm" icon={<Plus />} onClick={() => setCreating(true)}>Vazifa berish</Button>} />
      {!data ? <p className="subtle" style={{ padding: 16, margin: 0 }}>Yuklanmoqda…</p> : data.data.length === 0 ? (
        <EmptyState compact icon={<NotebookPen />} title="Vazifa berilmagan" action={teacher && <Button icon={<Plus />} onClick={() => setCreating(true)}>Vazifa berish</Button>} />
      ) : (
        <Table stack caption="Uyga vazifalar">
          <thead><tr><Th>Vazifa</Th><Th>Muddat</Th><Th>{teacher ? "Topshirdi" : "Holat"}</Th></tr></thead>
          <tbody>
            {data.data.map((a) => (
              <tr key={a.id}>
                <Td><Link className="a" href={`/dashboard/homework/${a.id}`}>{a.title}</Link></Td>
                <Td label="Muddat">{a.due_at ? fmtDateTime(a.due_at, tz) : "—"}</Td>
                <Td label={teacher ? "Topshirdi" : "Holat"}>
                  {a.stats ? (
                    <div style={{ display: "flex", alignItems: "center", gap: 8, minWidth: 140 }}>
                      <div style={{ flex: 1 }}><Progress value={a.stats.total ? (a.stats.submitted / a.stats.total) * 100 : 0} label="Topshirdi" /></div>
                      <b>{a.stats.submitted}/{a.stats.total}</b>
                    </div>
                  ) : a.my ? <Badge tone={HW_TONE[a.my.status]} dot>{labels[a.my.status]}{a.my.score != null ? ` · ${a.my.score}/${a.max_score}` : ""}</Badge> : null}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
      {teacher && <AssignmentDialog open={creating} onClose={() => setCreating(false)} groupId={groupId} onSaved={() => void load()} />}
    </Card>
  );
}
