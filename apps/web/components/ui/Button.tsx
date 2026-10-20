import Link from "next/link";
import { forwardRef, type ButtonHTMLAttributes, type ComponentProps, type ReactNode } from "react";
import s from "./Button.module.css";
import { cx } from "./cx";

export type ButtonVariant = "primary" | "secondary" | "soft" | "ghost" | "danger" | "dangerGhost" | "telegram";
export type ButtonSize = "sm" | "md" | "lg" | "xl";

type Common = { variant?: ButtonVariant; size?: ButtonSize; block?: boolean; icon?: ReactNode; iconOnly?: boolean };

export function buttonClass({ variant = "primary", size = "md", block, iconOnly }: Common, extra?: string) {
  return cx(s.btn, s[variant], size !== "md" && s[size], block && s.block, iconOnly && s.icon, extra);
}

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & Common & { loading?: boolean };

/** Asosiy tugma. `iconOnly` bo'lsa `aria-label` majburiy. */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant, size, block, icon, iconOnly, loading, className, children, disabled, type = "button", ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      type={type}
      className={buttonClass({ variant, size, block, iconOnly }, cx(loading && s.loading, className))}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      {...rest}
    >
      {loading ? <span className={s.spinner} aria-hidden /> : icon}
      {children != null && <span className={s.label}>{children}</span>}
    </button>
  );
});

/** Havola ko'rinishidagi tugma (Next Link). */
export function ButtonLink({ variant, size, block, icon, iconOnly, className, children, ...rest }: ComponentProps<typeof Link> & Common) {
  return (
    <Link className={buttonClass({ variant, size, block, iconOnly }, className)} {...rest}>
      {icon}
      {children != null && <span>{children}</span>}
    </Link>
  );
}
