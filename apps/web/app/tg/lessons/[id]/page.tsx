"use client";

import { useParams } from "next/navigation";
import { useTg } from "../../_lib/TgProvider";
import { openLink } from "../../_lib/webapp";
import { Async, Page, Row, Section, useApi } from "../../_ui/ui";

type Material = { id: number; type: "VIDEO" | "FILE" | "LINK" | "TEXT"; title: string; url: string | null; content: string | null };
type Lesson = { id: number; title: string; body: string | null; materials: Material[]; module: { course: { title: string } } };

/** YouTube havolasini embed manziliga aylantirish; boshqa videolar <video> bilan. */
function youtubeEmbed(url: string): string | null {
  const m = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{6,})/);
  return m ? `https://www.youtube.com/embed/${m[1]}` : null;
}

const ICONS: Record<Material["type"], string> = { VIDEO: "🎬", FILE: "📎", LINK: "🔗", TEXT: "📄" };

export default function TgLesson() {
  const { id } = useParams<{ id: string }>();
  const { t } = useTg();
  const q = useApi<{ lesson: Lesson }>(`lessons/${id}`);
  return (
    <Async q={q}>
      {({ lesson }) => {
        const videos = lesson.materials.filter((m) => m.type === "VIDEO" && m.url);
        const texts = lesson.materials.filter((m) => m.type === "TEXT" && m.content);
        const links = lesson.materials.filter((m) => (m.type === "FILE" || m.type === "LINK") && m.url);
        return (
          <Page title={lesson.title} subtitle={lesson.module?.course?.title}>
            {videos.map((v) => {
              const yt = youtubeEmbed(v.url!);
              return (
                <Section key={v.id} title={v.title}>
                  {yt
                    ? <iframe className="tg-video" src={yt} allow="accelerometer; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowFullScreen title={v.title} />
                    : <video className="tg-video" src={v.url!} controls playsInline preload="metadata" />}
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
