import Link from "next/link";
import { api, me, publicSettings } from "@/lib/api";
import NewCourse from "./NewCourse";

type Course = { id: number; title: string; published: boolean; monthly_fee: number; modules_count: number };

export default async function Courses() {
  const [user, s, { data }] = await Promise.all([me(), publicSettings(), api<{ data: Course[] }>("/v1/courses")]);
  const currency = String(s?.["site.currency"] ?? "");
  return (
    <>
      <h1>Kurslar</h1>
      {user?.permissions.includes("course.manage") && <NewCourse />}
      <div className="grid">
        {(data.data ?? []).map((c) => (
          <Link href={`/dashboard/courses/${c.id}`} className="card" key={c.id}>
            <h2>{c.title}</h2>
            <p>
              {c.modules_count} modul · {c.monthly_fee.toLocaleString("uz-UZ")} {currency}
              {!c.published && <span className="tag">yashirin</span>}
            </p>
          </Link>
        ))}
        {(data.data ?? []).length === 0 && <p className="muted">Hali kurs yo&apos;q.</p>}
      </div>
    </>
  );
}
