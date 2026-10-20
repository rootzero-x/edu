"use client";

import { useEffect, useId, useRef, type CSSProperties, type ReactNode } from "react";
import { X } from "lucide-react";
import s from "./Overlay.module.css";
import { cx } from "./cx";
import { Button } from "./Button";

export type DialogProps = {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  icon?: ReactNode;
  tone?: "default" | "danger";
  footer?: ReactNode;
  width?: number;
  children?: ReactNode;
  /** Forma ichida bo'lsa — Enter bilan yuboriladi. */
  onSubmit?: (e: React.FormEvent<HTMLFormElement>) => void;
};

/**
 * Modal oyna — native <dialog>: fokus tuzog'i, Esc va fokusni qaytarish brauzerning o'zida.
 * Telefonda pastdan chiqadigan "sheet" ko'rinishida.
 */
export function Dialog({ open, onClose, title, description, icon, tone = "default", footer, width, children, onSubmit }: DialogProps) {
  const ref = useRef<HTMLDialogElement>(null);
  const titleId = useId();
  const descId = useId();

  useEffect(() => {
    const d = ref.current;
    if (!d) return;
    if (open && !d.open) d.showModal();
    else if (!open && d.open) d.close();
  }, [open]);

  const inner = (
    <>
      <div className={s.dHead}>
        {icon && <span className={cx(s.dIcon, tone === "danger" && s.danger)} aria-hidden>{icon}</span>}
        <div className={s.dTitle}>
          <h2 id={titleId}>{title}</h2>
          {description && <p id={descId} className={s.dDesc}>{description}</p>}
        </div>
        <Button variant="ghost" size="sm" iconOnly aria-label="Yopish" className={s.dClose} icon={<X />} onClick={onClose} />
      </div>
      {children && <div className={s.dBody}>{children}</div>}
      {footer && <div className={s.dFoot}>{footer}</div>}
    </>
  );

  return (
    <dialog
      ref={ref}
      className={s.dialog}
      style={width ? ({ "--w": `${width}px` } as CSSProperties) : undefined}
      aria-labelledby={titleId}
      aria-describedby={description ? descId : undefined}
      onCancel={(e) => { e.preventDefault(); onClose(); }}
      onClick={(e) => { if (e.target === ref.current) onClose(); }}
    >
      {open && (onSubmit ? (
        <form className={s.panel} onSubmit={(e) => { e.preventDefault(); onSubmit(e); }} noValidate={false}>{inner}</form>
      ) : (
        <div className={s.panel}>{inner}</div>
      ))}
    </dialog>
  );
}
