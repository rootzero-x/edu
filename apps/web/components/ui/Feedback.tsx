import type { CSSProperties, ReactNode } from "react";
import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import s from "./Surface.module.css";
import { cx } from "./cx";
import type { Tone } from "./Badge";

export function Skeleton({ width, height = 14, radius, className, style }: { width?: number | string; height?: number | string; radius?: number | string; className?: string; style?: CSSProperties }) {
  return <span aria-hidden className={cx(s.skeleton, className)} style={{ width: width ?? "100%", height, borderRadius: radius, ...style }} />;
}

export function Spinner({ size = 18, label = "Yuklanmoqda" }: { size?: number; label?: string }) {
  return <span role="status" aria-label={label} className={s.spinner} style={{ "--size": `${size}px` } as CSSProperties} />;
}

export function EmptyState({ icon, title, children, action, compact, level = 3 }: { icon?: ReactNode; title: ReactNode; children?: ReactNode; action?: ReactNode; compact?: boolean; level?: 1 | 2 | 3 }) {
  const H = `h${level}` as "h1" | "h2" | "h3";
  return (
    <div className={cx(s.empty, compact && s.compact)}>
      {icon && <div className={s.emptyIcon} aria-hidden>{icon}</div>}
      <H>{title}</H>
      {children && <p>{children}</p>}
      {action && <div className={s.emptyAction}>{action}</div>}
    </div>
  );
}

export function StatCard({ label, value, icon, hint, tone = "accent", progress, className }: {
  label: ReactNode; value: ReactNode; icon?: ReactNode; hint?: ReactNode; tone?: Tone; progress?: number | null; className?: string;
}) {
  return (
    <div className={cx(s.card, s.stat, s[`tone-${tone}`], className)}>
      <div className={s.statTop}>
        <span className={s.statLabel}>{label}</span>
        {icon && <span className={s.statIcon} aria-hidden>{icon}</span>}
      </div>
      <div className={s.statValue}>{value}</div>
      {progress != null && (
        <div className={s.statBar} role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(progress)}>
          <span style={{ width: `${Math.max(0, Math.min(100, progress))}%` }} />
        </div>
      )}
      {hint && <div className={s.statHint}>{hint}</div>}
    </div>
  );
}

export function PageHeader({ title, description, actions, back, badge }: {
  title: ReactNode; description?: ReactNode; actions?: ReactNode; back?: { href: string; label: string }; badge?: ReactNode;
}) {
  return (
    <header className={s.page}>
      <div className={s.pageText}>
        {back && <Link href={back.href} className={s.back}><ChevronLeft aria-hidden />{back.label}</Link>}
        <h1>{title}{badge}</h1>
        {description && <p className={s.pageDesc}>{description}</p>}
      </div>
      {actions && <div className={s.pageActions}>{actions}</div>}
    </header>
  );
}

export function Avatar({ name, src, size = 32 }: { name: string; src?: string | null; size?: number }) {
  const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join("") || "?";
  return (
    <span className={s.avatar} style={{ "--size": `${size}px` } as CSSProperties} aria-hidden>
      {src ? <img src={src} alt="" /> : initials}
    </span>
  );
}

export function Progress({ value, label }: { value: number; label?: string }) {
  const v = Math.max(0, Math.min(100, value));
  return (
    <div className={s.progress} role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(v)}>
      <span style={{ width: `${v}%` }} />
    </div>
  );
}
