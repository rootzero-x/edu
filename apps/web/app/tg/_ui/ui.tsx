"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
import { tg } from "../_lib/api";
import { useTg } from "../_lib/TgProvider";
import { haptic, openLink, webApp } from "../_lib/webapp";
import { isDenial, type Denial } from "@/components/AccessDenied";

/** GET so'rov holati. path null bo'lsa so'rov yuborilmaydi. */
export function useApi<T>(path: string | null) {
  const [state, setState] = useState<{ data: T | null; error: string | null; loading: boolean; denial?: Denial | null }>({ data: null, error: null, loading: !!path });
  const load = useCallback(async () => {
    if (!path) return;
    setState((s) => ({ ...s, loading: true, error: null }));
    const r = await tg<T>("GET", path);
    setState({
      data: r.ok ? r.data : null,
      error: r.ok ? null : (r.data as any)?.message || String(r.status),
      loading: false,
      denial: r.status === 403 && isDenial(r.data) ? r.data : null,
    });
  }, [path]);
  useEffect(() => {
    load();
  }, [load]);
  return { ...state, reload: load };
}

export function Page({ title, subtitle, children }: { title?: string; subtitle?: string; children: React.ReactNode }) {
  return (
    <main className="tg-page">
      {title && <h1 className="tg-title">{title}</h1>}
      {subtitle && <p className="tg-hint tg-sub">{subtitle}</p>}
      {children}
    </main>
  );
}

export function Section({ title, children, right }: { title?: string; children: React.ReactNode; right?: React.ReactNode }) {
  return (
    <section className="tg-section">
      {(title || right) && (
        <div className="tg-section-head">
          <span>{title}</span>
          {right}
        </div>
      )}
      <div className="tg-card">{children}</div>
    </section>
  );
}

export function Row({ href, onClick, icon, title, subtitle, right }: {
  href?: string; onClick?: () => void; icon?: React.ReactNode; title: React.ReactNode; subtitle?: React.ReactNode; right?: React.ReactNode;
}) {
  const body = (
    <>
      {icon && <span className="tg-row-icon">{icon}</span>}
      <span className="tg-row-main">
        <span className="tg-row-title">{title}</span>
        {subtitle && <span className="tg-row-sub">{subtitle}</span>}
      </span>
      {right && <span className="tg-row-right">{right}</span>}
      {(href || onClick) && <span className="tg-chevron">›</span>}
    </>
  );
  if (href) return <Link className="tg-row" href={href} onClick={() => haptic.tap()}>{body}</Link>;
  if (onClick) return <button className="tg-row" onClick={() => { haptic.tap(); onClick(); }}>{body}</button>;
  return <div className="tg-row">{body}</div>;
}

export function Loading() {
  const { t } = useTg();
  return <div className="tg-center small"><div className="tg-spinner" /><p className="tg-hint">{t("loading")}</p></div>;
}

export function Empty({ text, icon = "🗂" }: { text: string; icon?: string }) {
  return <div className="tg-empty"><div className="tg-emoji">{icon}</div><p className="tg-hint">{text}</p></div>;
}

export function Failed({ onRetry, text }: { onRetry?: () => void; text?: string | null }) {
  const { t } = useTg();
  return (
    <div className="tg-empty">
      <p className="tg-hint">{text || t("error")}</p>
      {onRetry && <button className="tg-btn ghost" onClick={onRetry}>{t("retry")}</button>}
    </div>
  );
}

/** Kursga yozilmagan foydalanuvchi: murojaat tugmasi bilan (matnlar sozlamalardan). */
export function Denied({ denial }: { denial: Denial }) {
  const url = denial.contact_url || (denial.contact?.startsWith("@") ? `https://t.me/${denial.contact.slice(1)}` : "");
  return (
    <div className="tg-empty">
      <div className="tg-empty-icon" aria-hidden>🔒</div>
      <p><b>{denial.title}</b></p>
      <p className="tg-hint">{denial.message}</p>
      {url && <button className="tg-btn" onClick={() => openLink(url)}>{denial.button || denial.contact}</button>}
    </div>
  );
}

/** Ma'lumot holatiga qarab: yuklanmoqda / xato / kontent. */
export function Async<T>({ q, children }: { q: { data: T | null; error: string | null; loading: boolean; reload: () => void; denial?: Denial | null }; children: (data: T) => React.ReactNode }) {
  if (q.loading && !q.data) return <Loading />;
  // Page ichida ham, tashqarisida ham ishlatiladi — ichma-ich <main> bo'lmasin.
  if (q.denial) return <Denied denial={q.denial} />;
  if (q.error || !q.data) return <Failed onRetry={q.reload} text={q.error} />;
  return <>{children(q.data)}</>;
}

export function Progress({ value, tone }: { value: number; tone?: "ok" | "warn" | "bad" }) {
  const v = Math.max(0, Math.min(100, value));
  const tn = tone ?? (v >= 80 ? "ok" : v >= 60 ? "warn" : "bad");
  return <div className={`tg-progress ${tn}`}><span style={{ width: `${v}%` }} /></div>;
}

/**
 * Telegram MainButton. Telegram tashqarisida (brauzerda sinash) sahifa ichidagi tugma ko'rsatiladi.
 * Bosish faqat faol holatda o'tadi (SDK nofaol tugma bosilishini ham yuborishi mumkin), ketma-ket
 * ikki bosishni handler o'zi to'xtatadi (progress).
 */
export function MainButton({ text, onClick, disabled, progress, hidden }: {
  text: string; onClick: () => void; disabled?: boolean; progress?: boolean; hidden?: boolean;
}) {
  const handler = useRef(onClick);
  handler.current = onClick;
  const blocked = useRef(false);
  blocked.current = Boolean(disabled || progress || hidden);
  const [native, setNative] = useState(false);

  useEffect(() => {
    const wa = webApp();
    if (!wa?.initData) return;
    setNative(true);
    const cb = () => {
      if (!blocked.current) handler.current();
    };
    wa.MainButton.onClick(cb);
    return () => {
      wa.MainButton.offClick(cb);
      wa.MainButton.hideProgress();
      wa.MainButton.hide();
    };
  }, []);

  useEffect(() => {
    const wa = webApp();
    if (!wa?.initData) return;
    const b = wa.MainButton;
    const theme = wa.themeParams ?? {};
    b.setText(text);
    // Tartib muhim: SDK hideProgress() nofaol tugmani qayta faollashtiradi, shuning uchun avval progress.
    if (progress) b.showProgress(false);
    else b.hideProgress();
    if (disabled && !progress) {
      b.disable();
      // Ko'p klientlar nofaol tugmani xiralashtirmaydi — rang bilan ko'rsatamiz.
      try {
        b.setParams({ color: theme.secondary_bg_color || "#c8c8cc", text_color: theme.hint_color || "#8a8f98" });
      } catch {
        /* eski versiya */
      }
    } else if (!progress) {
      b.enable();
      try {
        if (theme.button_color) b.setParams({ color: theme.button_color, text_color: theme.button_text_color || "#ffffff" });
      } catch {
        /* eski versiya */
      }
    }
    if (hidden) b.hide();
    else b.show();
  }, [text, disabled, progress, hidden]);

  if (native || hidden) return null;
  return <button className="tg-btn tg-main-fallback" disabled={disabled || progress} onClick={onClick}>{text}</button>;
}

export function Badge({ children, tone = "muted" }: { children: React.ReactNode; tone?: "ok" | "warn" | "bad" | "muted" | "accent" }) {
  return <span className={`tg-badge ${tone}`}>{children}</span>;
}
