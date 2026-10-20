"use client";

import { useEffect, useState } from "react";
import { History } from "lucide-react";
import { Badge, Dialog, EmptyState } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDate, fmtTime } from "@/lib/format";
import { timezone } from "@/lib/settings";
import type { Item } from "./SettingsEditor";
import s from "./settings.module.css";

type Entry = { id: number; created_at: string; actor: string | null; from: unknown; to: unknown; reset: boolean };

const show = (v: unknown) => (v === null || v === undefined ? "—" : typeof v === "string" ? v : JSON.stringify(v, null, 1));

/** Bitta sozlamaning o'zgarishlar tarixi (audit jurnalidan): kim, qachon, eski → yangi qiymat. */
export default function SettingHistory({ item, onClose }: { item: Item | null; onClose: () => void }) {
  const tz = timezone(useSiteSettings());
  const [rows, setRows] = useState<Entry[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (!item) return;
    setRows(null);
    setError(null);
    call<{ data: Entry[] }>("GET", `settings/${encodeURIComponent(item.key)}/history`).then((r) => (r.ok ? setRows(r.data.data) : setError(errorText(r.data))));
  }, [item]);
  return (
    <Dialog open={Boolean(item)} onClose={onClose} title="O'zgarishlar tarixi" description={item?.label} icon={<History />} width={620}>
      {error ? <p className="error">{error}</p> : !rows ? <p className="subtle">Yuklanmoqda…</p> : rows.length === 0 ? (
        <EmptyState compact icon={<History />} title="Hali o'zgartirilmagan">Bu sozlama boshlang&apos;ich qiymatida.</EmptyState>
      ) : (
        <ol className={s.history}>
          {rows.map((r) => (
            <li key={r.id}>
              <div className={s.historyHead}>
                <span>{fmtDate(r.created_at, tz)} {fmtTime(r.created_at, tz)}</span>
                <span>{r.actor ?? "Tizim"}</span>
                {r.reset && <Badge>Standartga qaytarildi</Badge>}
              </div>
              <div className={s.historyDiff}>
                <pre className={s.from}>{show(r.from)}</pre>
                <span aria-hidden>→</span>
                <span className="sr-only">yangi qiymat:</span>
                <pre className={s.to}>{show(r.to)}</pre>
              </div>
            </li>
          ))}
        </ol>
      )}
    </Dialog>
  );
}
