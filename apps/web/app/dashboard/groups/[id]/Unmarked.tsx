import Link from "next/link";
import { AlertTriangle, CheckCircle2 } from "lucide-react";
import { Badge, Card, CardHeader, EmptyState } from "@/components/ui";
import { fmtDate, fmtTime, fmtWeekday } from "@/lib/format";
import s from "./unmarked.module.css";

export type UnmarkedRow = { id: number; starts_at: string; group: { id: number; name: string }; members: number; marked: number };

/** O'tgan, davomati to'liq belgilanmagan darslar — bosib darhol tuzatish mumkin. */
export default function Unmarked({ rows, tz, title = "Belgilanmagan darslar", showGroup }: { rows: UnmarkedRow[]; tz: string; title?: string; showGroup?: boolean }) {
  return (
    <Card style={{ marginTop: 16 }}>
      <CardHeader title={title} description={rows.length ? `${rows.length} ta dars tuzatilishi kerak` : undefined}
        actions={rows.length ? <Badge tone="warning" icon={<AlertTriangle />}>{rows.length}</Badge> : undefined} />
      {rows.length === 0 ? (
        <EmptyState compact icon={<CheckCircle2 />} title="Barcha o'tgan darslar belgilangan" />
      ) : (
        <ul className={s.list}>
          {rows.map((r) => (
            <li key={r.id}>
              <Link href={`/dashboard/sessions/${r.id}`} className={s.item}>
                <span>
                  <b>{fmtDate(r.starts_at, tz)}</b> · {fmtWeekday(r.starts_at, tz)} · {fmtTime(r.starts_at, tz)}
                  {showGroup && <span className="subtle"> · {r.group.name}</span>}
                </span>
                <Badge tone={r.marked ? "warning" : "danger"}>{r.marked}/{r.members}</Badge>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
