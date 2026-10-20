"use client";

import { useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import { Button, cx } from "@/components/ui";
import { fmtAgo } from "@/lib/format";
import s from "./LiveStatus.module.css";

/** "Jonli · 10 soniya oldin" + qo'lda yangilash tugmasi. Polling holatini ko'rsatadi. */
export default function LiveStatus({ lastUpdated, refreshing, failed, onRefresh, seconds }: {
  lastUpdated: number | null; refreshing: boolean; failed: boolean; onRefresh: () => void; seconds: number;
}) {
  const [, force] = useState(0);
  useEffect(() => {
    const t = setInterval(() => force((n) => n + 1), 10_000);
    return () => clearInterval(t);
  }, []);
  const live = seconds > 0 && !failed;
  return (
    <div className={s.live}>
      <span className={cx(s.dot, live ? s.on : failed ? s.err : s.off)} aria-hidden />
      <span className={s.text} aria-live="polite">
        {failed ? "Aloqa uzildi" : seconds > 0 ? "Jonli" : "Avto-yangilanish o'chiq"}
        {lastUpdated && <span className={s.ago}> · {fmtAgo(lastUpdated)}</span>}
      </span>
      <Button variant="ghost" size="sm" iconOnly aria-label="Yangilash" onClick={onRefresh} icon={<RefreshCw className={refreshing ? s.spin : undefined} />} />
    </div>
  );
}
