"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";

type Lesson = { id: number; title: string; position: number; published: boolean };
type Module = { id: number; title: string; position: number; lessons: Lesson[] };
export type Course = { id: number; title: string; description: string | null; published: boolean; monthly_fee: number; modules: Module[] };

export default function CourseEditor({ initial, canManage }: { initial: Course; canManage: boolean }) {
  const router = useRouter();
  const [err, setErr] = useState<string | null>(null);
  const c = initial;

  async function run(method: string, path: string, body?: unknown) {
    setErr(null);
    const r = await call(method, path, body);
    if (!r.ok) setErr(errorText(r.data));
    else router.refresh();
  }

  const ask = (q: string) => (window.prompt(q) ?? "").trim();

  return (
    <>
      <h1>{c.title} {!c.published && <span className="tag">yashirin</span>}</h1>
      {c.description && <p className="muted">{c.description}</p>}
      {canManage && (
        <div className="toolbar">
          <button className="btn" onClick={() => run("PATCH", `courses/${c.id}`, { published: !c.published })}>
            {c.published ? "Yashirish" : "E'lon qilish"}
          </button>
          <button className="btn ghost" onClick={() => { const t = ask("Kurs nomi"); if (t) run("PATCH", `courses/${c.id}`, { title: t }); }}>Nomini o&apos;zgartirish</button>
          <button className="btn ghost" onClick={() => { const t = ask("Modul nomi"); if (t) run("POST", `courses/${c.id}/modules`, { title: t }); }}>+ Modul</button>
          <button className="btn danger" onClick={async () => {
            if (!window.confirm("Kursni o'chirasizmi?")) return;
            const r = await call("DELETE", `courses/${c.id}`);
            if (r.ok) router.push("/dashboard"); else setErr(errorText(r.data));
          }}>O&apos;chirish</button>
        </div>
      )}
      {err && <p className="error">{err}</p>}
      {c.modules.map((m) => (
        <div className="card" key={m.id}>
          <div className="row">
            <h2>{m.position}. {m.title}</h2>
            {canManage && (
              <span>
                <button className="link" onClick={() => { const t = ask("Dars nomi"); if (t) run("POST", `modules/${m.id}/lessons`, { title: t }); }}>+ dars</button>
                <button className="link danger" onClick={() => window.confirm("Modulni o'chirasizmi?") && run("DELETE", `modules/${m.id}`)}>o&apos;chirish</button>
              </span>
            )}
          </div>
          <ol>
            {m.lessons.map((l) => (
              <li key={l.id}>
                {l.title} {!l.published && <span className="tag">qoralama</span>}
                {canManage && (
                  <>
                    <button className="link" onClick={() => run("PATCH", `lessons/${l.id}`, { published: !l.published })}>{l.published ? "yashirish" : "e'lon qilish"}</button>
                    <button className="link" onClick={() => {
                      const url = ask("Material havolasi (video/fayl/sayt URL)");
                      if (url) run("POST", `lessons/${l.id}/materials`, { type: /youtu|vimeo/.test(url) ? "VIDEO" : "LINK", title: ask("Material nomi") || url, url });
                    }}>+ material</button>
                    <button className="link danger" onClick={() => window.confirm("Darsni o'chirasizmi?") && run("DELETE", `lessons/${l.id}`)}>o&apos;chirish</button>
                  </>
                )}
              </li>
            ))}
          </ol>
        </div>
      ))}
      {c.modules.length === 0 && <p className="muted">Hali modul yo&apos;q.</p>}
    </>
  );
}
