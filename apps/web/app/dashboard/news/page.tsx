import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import NewsView, { type NewsItem } from "./NewsView";

export const metadata: Metadata = { title: "Yangiliklar" };

type Feed = { data: NewsItem[]; meta: { unread: number } };

export default async function NewsPage() {
  const user = await me();
  const manage = Boolean(user?.permissions.includes("announcement.manage"));
  const isAdmin = user?.role === "ADMIN" || user?.role === "SUPER_ADMIN";
  const [feed, mine, groups] = await Promise.all([
    api<Feed>("/v1/announcements"),
    manage ? api<Feed>("/v1/announcements?scope=manage") : Promise.resolve(null),
    manage ? api<{ data: { id: number; name: string; teacher?: { id: number } | null }[] }>("/v1/groups") : Promise.resolve(null),
  ]);
  const ownGroups = (groups?.data.data ?? []).filter((g) => isAdmin || g.teacher?.id === user?.id).map((g) => ({ id: g.id, name: g.name }));
  return (
    <NewsView
      feed={feed.data.data ?? []}
      unread={feed.data.meta?.unread ?? 0}
      managed={mine?.data.data ?? null}
      groups={ownGroups}
      isAdmin={isAdmin}
    />
  );
}
