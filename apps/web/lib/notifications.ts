/** Bildirishnomalar markazi (GET /v1/notifications*) turlari. */
export type InboxItem = {
  id: number; kind: string; type: string; label: string; text: string; url: string | null;
  delivery: "sent" | "failed" | "not_linked" | "blocked" | "skipped" | "not_configured"; read: boolean; created_at: string;
};
export type UnreadSummary = { unread: number; news_unread: number; latest: InboxItem[] };
