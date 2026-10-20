import type { Metadata } from "next";
import NotFoundCard from "@/components/NotFoundCard";
import { ArrowLeft, ArrowRight, ExternalLink, FileText, Link2, PlayCircle } from "lucide-react";
import { api, publicSettings } from "@/lib/api";
import { LessonActions, MaterialActions, OpenFile } from "./LessonManage";
import { list } from "@/lib/settings";
import { embedUrl } from "@/lib/embed";
import AccessDenied, { isDenial, type Denial } from "@/components/AccessDenied";
import { Badge, ButtonLink, Card, CardBody, CardHeader, EmptyState, PageHeader, buttonClass } from "@/components/ui";
import s from "./lesson.module.css";

type Material = {
  id: number; type: "VIDEO" | "FILE" | "LINK" | "TEXT"; title: string; url: string | null; content: string | null;
  file?: { id: number; original_name: string; mime: string; size: number } | null;
};
type Lesson = {
  id: number; title: string; body: string | null; published: boolean;
  module: { id: number; title: string; course: { id: number; title: string } };
  materials: Material[];
};
type Res = { lesson: Lesson; can_edit?: boolean; nav: { prev: number | null; next: number | null; index: number | null; total: number } };

export async function generateMetadata({ params }: { params: Promise<{ id: string }> }): Promise<Metadata> {
  const { id } = await params;
  const r = await api<Res>(`/v1/lessons/${id}`);
  return { title: r.status === 200 ? r.data.lesson.title : "Dars" };
}

export default async function LessonPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const [r, st] = await Promise.all([api<Res | Denial>(`/v1/lessons/${id}`), publicSettings()]);
  if (r.status === 403 && isDenial(r.data)) {
    const d = r.data;
    return (
      <>
        <PageHeader back={d.course ? { href: `/dashboard/courses/${d.course.id}`, label: d.course.title } : undefined} title={d.course?.title ?? "Dars"} />
        <AccessDenied denial={d} />
      </>
    );
  }
  if (r.status !== 200) return <NotFoundCard />;
  const { lesson, nav, can_edit } = r.data as Res;
  const manage = Boolean(can_edit);
  const hosts = list<string>(st, "course.embed_hosts");
  const course = lesson.module.course;

  return (
    <>
      <PageHeader
        back={{ href: `/dashboard/courses/${course.id}`, label: course.title }}
        title={lesson.title}
        description={lesson.module.title}
        badge={<>{nav.index ? <Badge>{nav.index}/{nav.total}</Badge> : null}{manage && !lesson.published ? <Badge tone="warning">Qoralama</Badge> : null}</>}
        actions={manage ? <LessonActions lessonId={lesson.id} title={lesson.title} body={lesson.body} /> : undefined}
      />
      {lesson.body && (
        <Card>
          <CardBody><div className={s.body}>{lesson.body}</div></CardBody>
        </Card>
      )}
      {lesson.materials.length === 0 && !lesson.body && (
        <Card><EmptyState icon={<FileText />} title="Materiallar hali qo'shilmagan">{manage ? "Yuqoridagi «Fayl yuklash» yoki «Material» tugmasi orqali fayl, video, havola yoki matn qo'shing." : null}</EmptyState></Card>
      )}
      <div className={s.materials}>
        {lesson.materials.map((m) => {
          const embed = m.type === "VIDEO" && m.url ? embedUrl(m.url, hosts) : null;
          return (
            <Card key={m.id}>
              <CardHeader as="h3" title={<span className={s.mTitle}>{icon(m.type)} {m.title}</span>} actions={manage ? <MaterialActions material={m} /> : undefined} />
              <CardBody>
                {embed ? (
                  <div className={s.video}>
                    <iframe src={embed} title={m.title} loading="lazy" allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
                  </div>
                ) : m.type === "TEXT" ? (
                  <div className={s.body}>{m.content}</div>
                ) : m.file ? (
                  <OpenFile file={m.file} />
                ) : m.url ? (
                  <a className={buttonClass({ variant: "secondary" })} href={m.url} target="_blank" rel="noreferrer noopener">
                    <ExternalLink aria-hidden /> {m.type === "FILE" ? "Faylni ochish" : m.type === "VIDEO" ? "Videoni ochish" : "Havolani ochish"}
                  </a>
                ) : null}
              </CardBody>
            </Card>
          );
        })}
      </div>
      <nav className={s.nav} aria-label="Darslar bo'yicha harakat">
        {nav.prev ? <ButtonLink variant="secondary" href={`/dashboard/lessons/${nav.prev}`} icon={<ArrowLeft />}>Oldingi dars</ButtonLink> : <span />}
        {nav.next ? <ButtonLink href={`/dashboard/lessons/${nav.next}`}>Keyingi dars <ArrowRight aria-hidden /></ButtonLink> : <ButtonLink variant="secondary" href={`/dashboard/courses/${course.id}`}>Kursga qaytish</ButtonLink>}
      </nav>
    </>
  );
}

function icon(type: Material["type"]) {
  return type === "VIDEO" ? <PlayCircle aria-hidden /> : type === "LINK" ? <Link2 aria-hidden /> : <FileText aria-hidden />;
}
