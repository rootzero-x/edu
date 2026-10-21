"use client";

import Link from "next/link";
import { useCallback, useEffect, useId, useRef, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { Bell, CheckCheck, Megaphone } from "lucide-react";
import { Button } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call } from "@/lib/client";
import { fmtDateTime } from "@/lib/format";
import type { UnreadSummary } from "@/lib/notifications";
import { bool, refreshSeconds, timezone } from "@/lib/settings";
import { usePolling } from "@/lib/usePolling";
import s from "./Shell.module.css";

/**
 * Qo'ng'iroqcha: o'qilmagan bildirishnomalar (uyga vazifa, baho, davomat, to'lov…) va yangiliklar.
 * Ma'lumot polling bilan (ui.refresh_seconds); ochilganda so'nggi 5 ta xabar ko'rinadi.
 */
export default function NotificationBell({ onNews }: { onNews?: (n: number) => void }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const path = usePathname();
  const router = useRouter();
  const [data, setData] = useState<UnreadSummary | null>(null);
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const id = useId();
  const enabled = bool(st, "notifications.inbox_enabled", true);

  const fetcher = useCallback(async (signal: AbortSignal) => {
    const r = await call<UnreadSummary>("GET", "notifications/unread", undefined, { signal });
    return r.ok ? r.data : null;
  }, []);
  const apply = useCallback((d: UnreadSummary) => { setData(d); onNews?.(d.news_unread); }, [onNews]);
  const live = usePolling(fetcher, apply, { seconds: refreshSeconds(st) > 0 ? Math.max(30, refreshSeconds(st) * 3) : 0, immediate: true, enabled });
  useEffect(() => { setOpen(false); void live.refresh(); }, [path]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => { if (!root.current?.contains(e.target as Node)) setOpen(false); };
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") { setOpen(false); document.getElementById(`${id}-b`)?.focus(); } };
    document.addEventListener("mousedown", onDown);
    window.addEventListener("keydown", onKey);
    return () => { document.removeEventListener("mousedown", onDown); window.removeEventListener("keydown", onKey); };
  }, [open, id]);

  if (!enabled) return null;
  const unread = data?.unread ?? 0;

  async function go(itemId: number, url: string | null) {
    setOpen(false);
    await call("POST", "notifications/read", { ids: [itemId] });
    void live.refresh();
    router.push(url ?? "/dashboard/notifications");
  }

  async function readAll() {
    const r = await call<UnreadSummary>("POST", "notifications/read", { all: true });
    if (r.ok) setData((d) => (d ? { ...d, unread: 0, latest: [] } : d));
  }

  return (
    <div className={s.bell} ref={root}>
      <Button
        id={`${id}-b`}
        variant="ghost"
        iconOnly
        icon={<Bell />}
        aria-label={unread ? `Bildirishnomalar: ${unread} ta o'qilmagan` : "Bildirishnomalar"}
        aria-expanded={open}
        aria-controls={`${id}-p`}
        onClick={() => setOpen((o) => !o)}
      />
      {unread > 0 && <span className={s.bellDot} aria-hidden>{unread > 99 ? "99+" : unread}</span>}
      {open && (
        <div id={`${id}-p`} role="dialog" aria-label="Bildirishnomalar" className={s.bellPanel}>
          <div className={s.bellHead}>
            <strong>Bildirishnomalar</strong>
            {unread > 0 && <Button size="sm" variant="ghost" icon={<CheckCheck />} onClick={readAll}>Hammasini o&apos;qildi</Button>}
          </div>
          {data && data.news_unread > 0 && (
            <Link href="/dashboard/news" className={s.bellNews} onClick={() => setOpen(false)}>
              <Megaphone aria-hidden />{data.news_unread} ta o&apos;qilmagan yangilik
            </Link>
          )}
          {!data?.latest.length ? (
            <p className={s.bellEmpty}>Yangi bildirishnoma yo&apos;q</p>
          ) : (
            <ul className={s.bellList}>
              {data.latest.map((n) => (
                <li key={n.id}>
                  <button type="button" onClick={() => go(n.id, n.url)}>
                    <span className={s.bellType}>{n.label}<time>{fmtDateTime(n.created_at, tz)}</time></span>
                    <span className={s.bellText}>{n.text}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <Link href="/dashboard/notifications" className={s.bellAll} onClick={() => setOpen(false)}>Barcha bildirishnomalar</Link>
        </div>
      )}
    </div>
  );
}
