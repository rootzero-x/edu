"use client";

import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from "react";
import { CheckCircle2, Info, X, XCircle } from "lucide-react";
import s from "./Overlay.module.css";
import { cx } from "./cx";

type Kind = "success" | "error" | "info";
type Item = { id: number; kind: Kind; title: string; description?: string; leaving?: boolean };
type Api = {
  show: (kind: Kind, title: string, description?: string) => void;
  success: (title: string, description?: string) => void;
  error: (title: string, description?: string) => void;
  info: (title: string, description?: string) => void;
};

const Ctx = createContext<Api | null>(null);
const ICONS = { success: CheckCircle2, error: XCircle, info: Info };

/** Bildirishnomalar. Ekran o'quvchilari uchun aria-live hududi. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<Item[]>([]);
  const seq = useRef(0);

  const dismiss = useCallback((id: number) => {
    setItems((l) => l.map((t) => (t.id === id ? { ...t, leaving: true } : t)));
    setTimeout(() => setItems((l) => l.filter((t) => t.id !== id)), 180);
  }, []);

  const show = useCallback((kind: Kind, title: string, description?: string) => {
    const id = ++seq.current;
    setItems((l) => [...l.slice(-3), { id, kind, title, description }]);
    setTimeout(() => dismiss(id), kind === "error" ? 7000 : 4000);
  }, [dismiss]);

  const api = useMemo<Api>(() => ({
    show,
    success: (t, d) => show("success", t, d),
    error: (t, d) => show("error", t, d),
    info: (t, d) => show("info", t, d),
  }), [show]);

  return (
    <Ctx.Provider value={api}>
      {children}
      <div className={s.toasts} aria-live="polite" aria-relevant="additions">
        {items.map((t) => {
          const Icon = ICONS[t.kind];
          return (
            <div key={t.id} className={cx(s.toast, s[t.kind], t.leaving && s.leaving)} role={t.kind === "error" ? "alert" : "status"}>
              <span className={s.tIcon} aria-hidden><Icon /></span>
              <div className={s.tText}>
                <div className={s.tTitle}>{t.title}</div>
                {t.description && <div className={s.tDesc}>{t.description}</div>}
              </div>
              <button type="button" className={s.tClose} aria-label="Yopish" onClick={() => dismiss(t.id)}><X /></button>
            </div>
          );
        })}
      </div>
    </Ctx.Provider>
  );
}

export function useToast(): Api {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error("useToast ToastProvider ichida ishlatilishi kerak");
  return ctx;
}
