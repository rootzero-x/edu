import type { HTMLAttributes, ReactNode } from "react";
import s from "./Surface.module.css";
import { cx } from "./cx";

type CardProps = HTMLAttributes<HTMLDivElement> & { padded?: boolean; interactive?: boolean; as?: "div" | "section" | "article" };

export function Card({ padded, interactive, as: Tag = "div", className, ...rest }: CardProps) {
  return <Tag className={cx(s.card, padded && s.pad, interactive && s.interactive, className)} {...rest} />;
}

export function CardHeader({ title, description, actions, as: H = "h2" }: { title: ReactNode; description?: ReactNode; actions?: ReactNode; as?: "h2" | "h3" }) {
  return (
    <div className={s.head}>
      <div className={s.headText}>
        <H>{title}</H>
        {description && <p className={s.headDesc}>{description}</p>}
      </div>
      {actions && <div className={s.headActions}>{actions}</div>}
    </div>
  );
}

export function CardBody({ flush, className, ...rest }: HTMLAttributes<HTMLDivElement> & { flush?: boolean }) {
  return <div className={cx(flush ? s.flush : s.body, className)} {...rest} />;
}

export function CardFooter({ className, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cx(s.foot, className)} {...rest} />;
}
