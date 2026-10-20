"use client";

import Link from "next/link";
import { useEffect, useId, useRef, useState, type ReactElement, type ReactNode } from "react";
import s from "./Overlay.module.css";
import { cx } from "./cx";

export type MenuItem =
  | { type?: "item"; label: ReactNode; icon?: ReactNode; onSelect?: () => void; href?: string; danger?: boolean; disabled?: boolean }
  | { type: "separator" }
  | { type: "label"; label: ReactNode };

/**
 * Ochiladigan menyu. trigger — tugma elementi uchun render funksiyasi (aria-* va onClick beriladi).
 * Klaviatura: ↑/↓, Home/End, Esc; tashqariga bosilsa yopiladi.
 */
export function Dropdown({ trigger, items, align = "right", direction = "down" }: {
  trigger: (props: { onClick: () => void; "aria-haspopup": "menu"; "aria-expanded": boolean; "aria-controls": string; id: string }) => ReactElement;
  items: MenuItem[];
  align?: "left" | "right";
  direction?: "down" | "up";
}) {
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const id = useId();

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => { if (!root.current?.contains(e.target as Node)) setOpen(false); };
    document.addEventListener("mousedown", onDown);
    requestAnimationFrame(() => menu.current?.querySelector<HTMLElement>("[role=menuitem]:not(:disabled)")?.focus());
    return () => document.removeEventListener("mousedown", onDown);
  }, [open]);

  const focusables = () => Array.from(menu.current?.querySelectorAll<HTMLElement>("[role=menuitem]:not(:disabled)") ?? []);
  function onKey(e: React.KeyboardEvent) {
    const list = focusables();
    const i = list.indexOf(document.activeElement as HTMLElement);
    if (e.key === "ArrowDown") { e.preventDefault(); list[(i + 1) % list.length]?.focus(); }
    else if (e.key === "ArrowUp") { e.preventDefault(); list[(i - 1 + list.length) % list.length]?.focus(); }
    else if (e.key === "Home") { e.preventDefault(); list[0]?.focus(); }
    else if (e.key === "End") { e.preventDefault(); list[list.length - 1]?.focus(); }
    else if (e.key === "Escape" || e.key === "Tab") {
      setOpen(false);
      if (e.key === "Escape") document.getElementById(`${id}-t`)?.focus();
    }
  }

  return (
    <div className={s.dd} ref={root}>
      {trigger({ onClick: () => setOpen((o) => !o), "aria-haspopup": "menu", "aria-expanded": open, "aria-controls": `${id}-m`, id: `${id}-t` })}
      {open && (
        <div id={`${id}-m`} ref={menu} role="menu" aria-labelledby={`${id}-t`} className={cx(s.menu, s[align], direction === "up" && s.up)} onKeyDown={onKey}>
          {items.map((it, i) => {
            if (it.type === "separator") return <div key={i} className={s.sep} role="separator" />;
            if (it.type === "label") return <div key={i} className={s.menuLabel}>{it.label}</div>;
            const cls = cx(s.item, it.danger && s.danger);
            const content = <>{it.icon}{it.label}</>;
            const done = () => { setOpen(false); it.onSelect?.(); };
            return it.href ? (
              <Link key={i} href={it.href} role="menuitem" className={cls} onClick={done} tabIndex={-1}>{content}</Link>
            ) : (
              <button key={i} type="button" role="menuitem" className={cls} onClick={done} disabled={it.disabled} tabIndex={-1}>{content}</button>
            );
          })}
        </div>
      )}
    </div>
  );
}
