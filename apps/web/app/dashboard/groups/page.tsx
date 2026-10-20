import Link from "next/link";
import { api, me } from "@/lib/api";
import { WEEKDAYS, personName } from "@/lib/format";
import NewGroup from "./NewGroup";

type Group = {
  id: number; name: string; active: boolean; room: string | null; students_count: number;
  course: { title: string }; teacher: { first_name: string; last_name: string | null }; branch: { name: string };
  schedules: { weekday: number; start_time: string; end_time: string }[];
};

export default async function GroupsPage() {
  const user = await me();
  const manage = Boolean(user?.permissions.includes("group.manage"));
  const [{ data }, refs] = await Promise.all([
    api<{ data: Group[] }>("/v1/groups"),
    manage
      ? Promise.all([api<{ data: any[] }>("/v1/courses"), api<{ data: any[] }>("/v1/branches"), api<{ data: any[] }>("/v1/users?role=TEACHER")])
      : Promise.resolve(null),
  ]);
  return (
    <>
      <h1>Guruhlar</h1>
      {manage && refs && <NewGroup courses={refs[0].data.data ?? []} branches={refs[1].data.data ?? []} teachers={refs[2].data.data ?? []} />}
      <table>
        <thead><tr><th>Guruh</th><th>Kurs</th><th>O&apos;qituvchi</th><th>Jadval</th><th>O&apos;quvchilar</th></tr></thead>
        <tbody>
          {(data.data ?? []).map((g) => (
            <tr key={g.id}>
              <td><Link className="a" href={`/dashboard/groups/${g.id}`}>{g.name}</Link> {!g.active && <span className="tag">yopiq</span>}</td>
              <td>{g.course?.title}</td>
              <td>{g.teacher ? personName(g.teacher) : ""}</td>
              <td>{g.schedules.map((s) => `${WEEKDAYS[s.weekday]} ${s.start_time.slice(0, 5)}`).join(", ")}</td>
              <td>{g.students_count}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {(data.data ?? []).length === 0 && <p className="muted">Guruhlar yo&apos;q.</p>}
    </>
  );
}
