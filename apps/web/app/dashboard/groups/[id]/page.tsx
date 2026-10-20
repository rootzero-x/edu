import Link from "next/link";
import { notFound } from "next/navigation";
import { api, me, publicSettings } from "@/lib/api";
import { WEEKDAYS, fmtDateTime, personName } from "@/lib/format";
import GroupAdmin from "./GroupAdmin";

export default async function GroupPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const user = await me();
  const g = await api<{ group: any }>(`/v1/groups/${id}`);
  if (g.status !== 200) notFound();
  const manage = Boolean(user?.permissions.includes("group.manage"));
  const [sessions, report, s, students] = await Promise.all([
    api<{ data: any[] }>(`/v1/groups/${id}/sessions`),
    api<{ data: any[] }>(`/v1/groups/${id}/attendance`),
    publicSettings(),
    manage ? api<{ data: any[] }>("/v1/users?role=STUDENT") : Promise.resolve(null),
  ]);
  const group = g.data.group;
  const tz = String(s?.["app.timezone"] ?? "Asia/Tashkent");
  const labels = (s?.["attendance.status_labels"] ?? {}) as Record<string, string>;
  const now = Date.now();
  const list = sessions.data.data ?? [];
  const past = list.filter((x) => new Date(x.starts_at).getTime() <= now).reverse().slice(0, 12);
  const upcoming = list.filter((x) => new Date(x.starts_at).getTime() > now).slice(0, 6);

  return (
    <>
      <h1>{group.name} {!group.active && <span className="tag">yopiq</span>}</h1>
      <p className="muted">
        {group.course?.title} · {group.teacher ? personName(group.teacher) : ""} · {group.branch?.name}
        {group.room ? ` · ${group.room}` : ""} · {group.schedules.map((x: any) => `${WEEKDAYS[x.weekday]} ${x.start_time.slice(0, 5)}–${x.end_time.slice(0, 5)}`).join(", ")}
      </p>

      <div className="grid2">
        <div className="card">
          <h2>O&apos;quvchilar ({group.students.length})</h2>
          {manage && <GroupAdmin groupId={group.id} members={group.students} allStudents={students?.data.data ?? []} />}
          {!manage && <ul>{group.students.map((st: any) => <li key={st.id}>{personName(st)}</li>)}</ul>}
        </div>
        <div className="card">
          <h2>Davomat</h2>
          <table>
            <thead><tr><th>O&apos;quvchi</th>{Object.keys(labels).map((k) => <th key={k}>{labels[k]}</th>)}<th>%</th></tr></thead>
            <tbody>
              {(report.data.data ?? []).map((r) => (
                <tr key={r.student_id}><td>{r.name}</td>{Object.keys(labels).map((k) => <td key={k}>{r.counts[k] ?? 0}</td>)}<td>{r.rate}</td></tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="grid2">
        <div className="card">
          <h2>O&apos;tgan darslar</h2>
          <ul className="plain">
            {past.map((x) => (
              <li key={x.id}>
                <Link className="a" href={`/dashboard/sessions/${x.id}`}>{fmtDateTime(x.starts_at, tz)}</Link>
                {x.cancelled ? <span className="tag">bekor</span> : x.marked_count ? <span className="tag ok">belgilangan</span> : <span className="tag warn">belgilanmagan</span>}
              </li>
            ))}
            {past.length === 0 && <li className="muted">Hali dars bo&apos;lmagan.</li>}
          </ul>
        </div>
        <div className="card">
          <h2>Keyingi darslar</h2>
          <ul className="plain">
            {upcoming.map((x) => <li key={x.id}>{fmtDateTime(x.starts_at, tz)} {x.cancelled && <span className="tag">bekor</span>}</li>)}
            {upcoming.length === 0 && <li className="muted">Rejalashtirilmagan.</li>}
          </ul>
        </div>
      </div>
    </>
  );
}
