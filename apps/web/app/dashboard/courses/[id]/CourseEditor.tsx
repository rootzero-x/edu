"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { BookOpen, FileText, Layers, Lock, MoreHorizontal, Paperclip, Pencil, Plus, Trash2 } from "lucide-react";
import { Badge, Button, Card, CardHeader, Dropdown, EmptyState, PageHeader, Switch, useConfirm, usePrompt, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";
import AccessDenied, { type Denial } from "@/components/AccessDenied";
import s from "./editor.module.css";

type Lesson = { id: number; title: string; position: number; published: boolean };
type Module = { id: number; title: string; position: number; lessons: Lesson[] };
export type Course = {
  id: number; title: string; description: string | null; cover_url?: string | null; published: boolean;
  monthly_fee: number; duration_months?: number; modules: Module[];
};

export default function CourseEditor({ initial, canManage, access }: { initial: Course; canManage: boolean; access?: { granted: boolean } & Denial }) {
  const locked = !canManage && access?.granted === false;
  const router = useRouter();
  const toast = useToast();
  const confirm = useConfirm();
  const prompt = usePrompt();
  const [c, setC] = useState(initial);
  useEffect(() => setC(initial), [initial]);

  /** Server javobidan keyin sahifani yangilaydi; xatoda toast. */
  async function run(method: string, path: string, body?: unknown, ok?: string) {
    const r = await call(method, path, body);
    if (!r.ok) { toast.error("Saqlanmadi", errorText(r.data)); return false; }
    if (ok) toast.success(ok);
    router.refresh();
    return true;
  }

  /** Optimistic: avval ekranda o'zgartiradi, xato bo'lsa qaytaradi. */
  async function optimistic(next: Course, method: string, path: string, body: unknown, ok: string) {
    const prev = c;
    setC(next);
    const r = await call(method, path, body);
    if (!r.ok) { setC(prev); toast.error("Saqlanmadi", errorText(r.data)); return; }
    toast.success(ok);
    router.refresh();
  }

  const editCourse = () => prompt({
    title: "Kursni tahrirlash",
    fields: [
      { name: "title", label: "Nomi", required: true, defaultValue: c.title },
      { name: "description", label: "Tavsif", type: "textarea", defaultValue: c.description ?? "" },
      { name: "cover_url", label: "Muqova rasmi URL", type: "url", defaultValue: c.cover_url ?? "", placeholder: "https://…" },
      { name: "duration_months", label: "Davomiyligi (oy)", type: "number", defaultValue: String(c.duration_months ?? 1) },
    ],
    onSubmit: async (v) => {
      const r = await call("PATCH", `courses/${c.id}`, {
        title: v.title, description: v.description || null, cover_url: v.cover_url || null,
        duration_months: Math.max(1, Number(v.duration_months || 1)),
      });
      if (!r.ok) return errorText(r.data);
      toast.success("Kurs saqlandi");
      router.refresh();
    },
  });

  const addModule = () => prompt({
    title: "Yangi modul",
    fields: [{ name: "title", label: "Modul nomi", required: true }],
    submitText: "Qo'shish",
    onSubmit: async (v) => {
      const r = await call("POST", `courses/${c.id}/modules`, { title: v.title });
      if (!r.ok) return errorText(r.data);
      toast.success("Modul qo'shildi");
      router.refresh();
    },
  });

  const addLesson = (m: Module) => prompt({
    title: "Yangi dars",
    description: m.title,
    fields: [{ name: "title", label: "Dars nomi", required: true }],
    submitText: "Qo'shish",
    onSubmit: async (v) => {
      const r = await call("POST", `modules/${m.id}/lessons`, { title: v.title });
      if (!r.ok) return errorText(r.data);
      toast.success("Dars qo'shildi");
      router.refresh();
    },
  });

  const renameModule = (m: Module) => prompt({
    title: "Modul nomi",
    fields: [{ name: "title", label: "Nomi", required: true, defaultValue: m.title }],
    onSubmit: async (v) => {
      const r = await call("PATCH", `modules/${m.id}`, { title: v.title });
      if (!r.ok) return errorText(r.data);
      toast.success("Saqlandi");
      router.refresh();
    },
  });

  const renameLesson = (l: Lesson) => prompt({
    title: "Dars nomi",
    fields: [{ name: "title", label: "Nomi", required: true, defaultValue: l.title }],
    onSubmit: async (v) => {
      const r = await call("PATCH", `lessons/${l.id}`, { title: v.title });
      if (!r.ok) return errorText(r.data);
      toast.success("Saqlandi");
      router.refresh();
    },
  });

  const addMaterial = (l: Lesson) => prompt({
    title: "Material qo'shish",
    description: l.title,
    fields: [
      { name: "type", label: "Turi", type: "select", required: true, defaultValue: "LINK", options: [
        { value: "VIDEO", label: "Video (YouTube, Vimeo …)" }, { value: "FILE", label: "Fayl havolasi" },
        { value: "LINK", label: "Sayt havolasi" }, { value: "TEXT", label: "Matn" },
      ] },
      { name: "title", label: "Nomi", required: true },
      { name: "url", label: "Havola (URL)", type: "url", placeholder: "https://…", hint: "Video, fayl yoki havola uchun" },
      { name: "content", label: "Matn", type: "textarea", hint: "Faqat «Matn» turi uchun" },
    ],
    submitText: "Qo'shish",
    onSubmit: async (v) => {
      const r = await call("POST", `lessons/${l.id}/materials`, {
        type: v.type, title: v.title, url: v.type === "TEXT" ? null : v.url || null, content: v.type === "TEXT" ? v.content : null,
      });
      if (!r.ok) return errorText(r.data);
      toast.success("Material qo'shildi");
      router.refresh();
    },
  });

  async function remove(kind: "course" | "module" | "lesson", id: number, name: string) {
    const titles = { course: "Kursni o'chirasizmi?", module: "Modulni o'chirasizmi?", lesson: "Darsni o'chirasizmi?" };
    if (!(await confirm({ title: titles[kind], description: `«${name}» butunlay o'chiriladi. Bu amalni qaytarib bo'lmaydi.`, confirmText: "O'chirish", tone: "danger" }))) return;
    if (kind === "course") {
      const r = await call("DELETE", `courses/${id}`);
      if (!r.ok) return toast.error("O'chirilmadi", errorText(r.data));
      toast.success("Kurs o'chirildi");
      return router.push("/dashboard/courses");
    }
    await run("DELETE", `${kind === "module" ? "modules" : "lessons"}/${id}`, undefined, "O'chirildi");
  }

  const lessons = c.modules.reduce((n, m) => n + m.lessons.length, 0);

  return (
    <>
      <PageHeader
        back={{ href: "/dashboard/courses", label: "Kurslar" }}
        title={c.title}
        badge={c.published ? <Badge tone="success" dot>E&apos;lon qilingan</Badge> : <Badge tone="warning" dot>Yashirin</Badge>}
        actions={canManage && (
          <>
            <Switch
              label="E'lon qilish"
              checked={c.published}
              onChange={(e) => optimistic({ ...c, published: e.target.checked }, "PATCH", `courses/${c.id}`, { published: e.target.checked }, e.target.checked ? "Kurs e'lon qilindi" : "Kurs yashirildi")}
            />
            <Button variant="secondary" icon={<Pencil />} onClick={editCourse}>Tahrirlash</Button>
            <Button icon={<Plus />} onClick={addModule}>Modul</Button>
            <Dropdown
              trigger={(p) => <Button variant="ghost" iconOnly aria-label="Boshqa amallar" icon={<MoreHorizontal />} {...p} />}
              items={[{ label: "Kursni o'chirish", icon: <Trash2 />, danger: true, onSelect: () => remove("course", c.id, c.title) }]}
            />
          </>
        )}
      />
      <div className={s.facts}>
        <Badge icon={<Layers />}>{c.modules.length} modul</Badge>
        <Badge icon={<BookOpen />}>{lessons} dars</Badge>
        {c.duration_months ? <Badge>{c.duration_months} oy</Badge> : null}
      </div>
      {c.description && <p className={`muted ${s.desc}`}>{c.description}</p>}
      {locked && access && <div className={s.denied}><AccessDenied denial={access} compact /></div>}

      {c.modules.length === 0 ? (
        <Card><EmptyState icon={<Layers />} title="Hali modul yo'q" action={canManage && <Button icon={<Plus />} onClick={addModule}>Birinchi modul</Button>}>Kurs modullarga, modullar esa darslarga bo&apos;linadi.</EmptyState></Card>
      ) : (
        <div className={s.modules}>
          {c.modules.map((m) => (
            <Card key={m.id} as="section" aria-label={m.title}>
              <CardHeader
                title={<><span className={s.num}>{m.position}</span>{m.title}</>}
                description={`${m.lessons.length} dars`}
                actions={canManage && (
                  <>
                    <Button size="sm" variant="soft" icon={<Plus />} onClick={() => addLesson(m)}>Dars</Button>
                    <Dropdown
                      trigger={(p) => <Button size="sm" variant="ghost" iconOnly aria-label="Modul amallari" icon={<MoreHorizontal />} {...p} />}
                      items={[
                        { label: "Nomini o'zgartirish", icon: <Pencil />, onSelect: () => renameModule(m) },
                        { type: "separator" },
                        { label: "O'chirish", icon: <Trash2 />, danger: true, onSelect: () => remove("module", m.id, m.title) },
                      ]}
                    />
                  </>
                )}
              />
              {m.lessons.length === 0 ? (
                <p className="subtle" style={{ padding: "14px 20px", margin: 0 }}>Darslar yo&apos;q.</p>
              ) : (
                <ol className={s.lessons}>
                  {m.lessons.map((l) => (
                    <li key={l.id} className={s.lesson}>
                      <span className={s.lessonIcon} aria-hidden>{locked ? <Lock /> : <FileText />}</span>
                      <Link className={s.lessonTitle} href={`/dashboard/lessons/${l.id}`} aria-label={locked ? `${l.title} — yopiq` : undefined}>{l.title}</Link>
                      {!l.published && <Badge tone="warning">Qoralama</Badge>}
                      {canManage && (
                        <span className={s.lessonActions}>
                          <Switch
                            aria-label={`«${l.title}» e'lon qilingan`}
                            checked={l.published}
                            onChange={(e) => optimistic(
                              { ...c, modules: c.modules.map((x) => (x.id === m.id ? { ...x, lessons: x.lessons.map((y) => (y.id === l.id ? { ...y, published: e.target.checked } : y)) } : x)) },
                              "PATCH", `lessons/${l.id}`, { published: e.target.checked }, e.target.checked ? "Dars e'lon qilindi" : "Dars yashirildi",
                            )}
                          />
                          <Dropdown
                            trigger={(p) => <Button size="sm" variant="ghost" iconOnly aria-label="Dars amallari" icon={<MoreHorizontal />} {...p} />}
                            items={[
                              { label: "Material qo'shish", icon: <Paperclip />, onSelect: () => addMaterial(l) },
                              { label: "Nomini o'zgartirish", icon: <Pencil />, onSelect: () => renameLesson(l) },
                              { type: "separator" },
                              { label: "O'chirish", icon: <Trash2 />, danger: true, onSelect: () => remove("lesson", l.id, l.title) },
                            ]}
                          />
                        </span>
                      )}
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          ))}
        </div>
      )}
    </>
  );
}
