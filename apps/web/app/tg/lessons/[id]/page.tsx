"use client";

import { useParams } from "next/navigation";
import { useTg } from "../../_lib/TgProvider";
import { openLink } from "../../_lib/webapp";
import { Async, Page, Row, Section, useApi } from "../../_ui/ui";
import { embedUrl } from "@/lib/embed";

type Material = { id: number; type: "VIDEO" | "FILE" | "LINK" | "TEXT"; title: string; url: string | null; content: string | null };
type Lesson = { id: number; title: string; body: string | null; materials: Material[]; module: { course: { title: string } } };

const ICONS: Record<Material["type"], string> = { VIDEO: "🎬", FILE: "📎", LINK: "🔗", TEXT: "📄" };

export default function TgLesson() {
  const { id } = useParams<{ id: string }>();
  const { t, settings } = useTg();
  const hosts: string[] = Array.isArray(settings["course.embed_hosts"]) ? settings["course.embed_hosts"] : [];
  const q = useApi<{ lesson: Lesson }>(`lessons/${id}`);
  return (
    <Async q={q} title={t("lesson")}>
      {({ lesson }) => {
        const videos = lesson.materials.filter((m) => m.type === "VIDEO" && m.url);
        const texts = lesson.materials.filter((m) => m.type === "TEXT" && m.content);
        const links = lesson.materials.filter((m) => (m.type === "FILE" || m.type === "LINK") && m.url);
        return (
          <Page title={lesson.title} subtitle={lesson.module?.course?.title}>
            {videos.map((v) => {
              const yt = embedUrl(v.url!, hosts);
              return (
                <Section key={v.id} title={v.title}>
                  {yt
                    ? <iframe className="tg-video" src={yt} allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowFullScreen title={v.title} />
                    : /\.(mp4|webm|ogg|mov)(\?|#|$)/i.test(v.url!)
                      ? <video className="tg-video" src={v.url!} controls playsInline preload="metadata" />
                      : <Row icon="🎬" title={v.title} onClick={() => openLink(v.url!)} />}
                </Section>
              );
            })}
            {lesson.body && <Section><div className="tg-prose">{lesson.body}</div></Section>}
            {texts.map((m) => <Section key={m.id} title={m.title}><div className="tg-prose">{m.content}</div></Section>)}
            {links.length > 0 && (
              <Section title={t("materials")}>
                {links.map((m) => <Row key={m.id} icon={ICONS[m.type]} title={m.title} onClick={() => openLink(m.url!)} />)}
              </Section>
            )}
          </Page>
        );
      }}
    </Async>
  );
}
