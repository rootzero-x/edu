"use client";

import { useId, useRef, useState, type ReactNode } from "react";
import s from "./Overlay.module.css";
import { cx } from "./cx";

export type TabItem = { id: string; label: ReactNode; icon?: ReactNode; count?: number; content?: ReactNode };

/**
 * WAI-ARIA tabs: ←/→, Home/End bilan o'tish (roving tabindex).
 * `content` berilsa panelni o'zi chizadi; aks holda `value/onChange` bilan boshqariladigan filtr sifatida ishlaydi.
 */
export function Tabs({ items, value, onChange, variant = "line", label }: {
  items: TabItem[]; value?: string; onChange?: (id: string) => void; variant?: "line" | "pills"; label?: string;
}) {
  const [inner, setInner] = useState(items[0]?.id);
  const active = value ?? inner;
  const base = useId();
  const refs = useRef<Record<string, HTMLButtonElement | null>>({});
  const select = (id: string) => { setInner(id); onChange?.(id); };

  function onKey(e: React.KeyboardEvent, idx: number) {
    let next = -1;
    if (e.key === "ArrowRight") next = (idx + 1) % items.length;
    else if (e.key === "ArrowLeft") next = (idx - 1 + items.length) % items.length;
    else if (e.key === "Home") next = 0;
    else if (e.key === "End") next = items.length - 1;
    if (next < 0) return;
    e.preventDefault();
    const id = items[next].id;
    select(id);
    refs.current[id]?.focus();
  }

  const panel = items.find((t) => t.id === active)?.content;
  return (
    <>
      <div role="tablist" aria-label={label} className={cx(s.tablist, variant === "pills" && s.pills)}>
        {items.map((t, i) => (
          <button
            key={t.id}
            ref={(el) => { refs.current[t.id] = el; }}
            role="tab"
            type="button"
            id={`${base}-${t.id}`}
            aria-selected={t.id === active}
            aria-controls={panel !== undefined ? `${base}-${t.id}-p` : undefined}
            tabIndex={t.id === active ? 0 : -1}
            className={s.tab}
            onClick={() => select(t.id)}
            onKeyDown={(e) => onKey(e, i)}
          >
            {t.icon}{t.label}{t.count != null && <span className={s.count}>{t.count}</span>}
          </button>
        ))}
      </div>
      {panel !== undefined && (
        <div role="tabpanel" id={`${base}-${active}-p`} aria-labelledby={`${base}-${active}`} tabIndex={0}>
          {panel}
        </div>
      )}
    </>
  );
}
