"use client";

import { useState } from "react";
import { useTg } from "../_lib/TgProvider";
import { tg } from "../_lib/api";
import { haptic } from "../_lib/webapp";
import { Async, Badge, Empty, Page, Section, useApi } from "../_ui/ui";
import { fmtDate } from "@/lib/format";

type Item = { id: number; title: string; body: string; image_url: string | null; pinned: boolean; published_at: string; read: boolean; author: string | null };

/** Yangiliklar: bosilganda to'liq ochiladi va o'qilgan deb belgilanadi. */
export default function TgNews() {
  const { t, tz } = useTg();
  const q = useApi<{ data: Item[] }>("announcements");
  const [open, setOpen] = useState<number | null>(null);
  const [read, setRead] = useState<Set<number>>(new Set());

  const toggle = (a: Item) => {
    haptic.select();
    setOpen((o) => (o === a.id ? null : a.id));
    if (!a.read && !read.has(a.id)) {
      setRead((r) => new Set(r).add(a.id));
      // Xato bo'lsa — keyingi ochilishda yana "yangi" bo'lib turadi.
      void tg("POST", `announcements/${a.id}/read`).then((r) => {
        if (!r.ok) setRead((x) => { const n = new Set(x); n.delete(a.id); return n; });
      });
    }
  };

  return (
    <Async q={q} title={t("nav_news")}>
      {({ data }) => (
        <Page title={t("nav_news")}>
          {data.length === 0 && <Empty text={t("news_empty")} icon="📭" />}
          {data.map((a) => {
            const unread = !a.read && !read.has(a.id);
            return (
              <Section key={a.id}>
                <button type="button" className="tg-news" onClick={() => toggle(a)} aria-expanded={open === a.id}>
                  <span className="tg-news-head">
                    <b>{a.title}</b>
                    <span>
                      {a.pinned && <Badge tone="accent">{t("news_pinned")}</Badge>} {unread && <Badge tone="warn">{t("news_new")}</Badge>}
                    </span>
                  </span>
                  <span className="tg-hint">{fmtDate(a.published_at, tz)}{a.author ? ` · ${a.author}` : ""}</span>
                  {a.image_url && open === a.id && <img src={a.image_url} alt="" className="tg-news-img" loading="lazy" />}
                  <span className={open === a.id ? "tg-prose" : "tg-prose tg-clamp"}>{a.body}</span>
                </button>
              </Section>
            );
          })}
        </Page>
      )}
    </Async>
  );
}
