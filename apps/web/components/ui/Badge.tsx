import type { HTMLAttributes, ReactNode } from "react";
import s from "./Surface.module.css";
import { cx } from "./cx";

export type Tone = "neutral" | "accent" | "success" | "warning" | "danger" | "info";

export function Badge({ tone = "neutral", dot, icon, className, children, ...rest }: HTMLAttributes<HTMLSpanElement> & { tone?: Tone; dot?: boolean; icon?: ReactNode }) {
  return (
    <span className={cx(s.badge, s[tone], dot && s.badgeDot, className)} {...rest}>
      {icon}
      {children}
    </span>
  );
}
