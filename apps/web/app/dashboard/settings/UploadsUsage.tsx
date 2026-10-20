"use client";

import { useEffect, useState } from "react";
import { HardDrive } from "lucide-react";
import { Card, Progress } from "@/components/ui";
import { call } from "@/lib/client";
import { fmtSize } from "@/lib/upload";
import s from "./settings.module.css";

type Usage = { files: number; bytes: number; quota_bytes: number | null; max_bytes: number; allowed_types: string[]; paths: string[] };

/** "Fayllar" bo'limi: band joy va kvota, ruxsat etilgan turlar; zaxira papkasi — faqat super-adminga. */
export default function UploadsUsage({ showPaths }: { showPaths: boolean }) {
  const [u, setU] = useState<Usage | null>(null);
  useEffect(() => { call<Usage>("GET", "files/usage").then((r) => r.ok && setU(r.data)); }, []);
  if (!u) return null;
  const percent = u.quota_bytes ? Math.min(100, (u.bytes / u.quota_bytes) * 100) : null;
  return (
    <Card className={s.usage}>
      <div className={s.usageHead}><HardDrive aria-hidden /><strong>Band joy</strong></div>
      <div className={s.usageValue}>{fmtSize(u.bytes)}{u.quota_bytes ? <span className="subtle"> / {fmtSize(u.quota_bytes)}</span> : <span className="subtle"> · cheklanmagan</span>}</div>
      {percent != null && <Progress value={percent} label="Band joy" />}
      <p className="subtle" style={{ margin: 0 }}>
        {u.files} ta fayl · bitta fayl {fmtSize(u.max_bytes)} gacha · turlar: {u.allowed_types.join(", ") || "—"}
      </p>
      {showPaths && <p className="subtle" style={{ margin: 0, fontSize: "var(--text-xs)" }}>Zaxiraga kiritiladigan papka: <code>{u.paths.join(", ")}</code></p>}
    </Card>
  );
}
