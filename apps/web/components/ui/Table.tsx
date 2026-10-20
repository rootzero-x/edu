import type { HTMLAttributes, TableHTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from "react";
import s from "./Surface.module.css";
import { cx } from "./cx";

/**
 * Jadval: kichik ekranda gorizontal aylantiriladi. `caption` — ekran o'quvchilari uchun.
 * `stack` — telefonda har qator kartaga aylanadi (ustun nomi `Td label` dan olinadi).
 */
export function Table({ caption, stack, className, children, ...rest }: TableHTMLAttributes<HTMLTableElement> & { caption?: string; stack?: boolean }) {
  return (
    <div className={s.tableWrap} role="region" aria-label={caption} tabIndex={caption ? 0 : undefined}>
      <table className={cx(s.table, stack && s.stack, className)} {...rest}>
        {caption && <caption className="sr-only">{caption}</caption>}
        {children}
      </table>
    </div>
  );
}

type Align = { numeric?: boolean; actions?: boolean };
export function Th({ numeric, actions, className, ...rest }: ThHTMLAttributes<HTMLTableCellElement> & Align) {
  return <th scope="col" className={cx(numeric && s.num, actions && s.actions, className)} {...rest} />;
}
export function Td({ numeric, actions, label, className, ...rest }: TdHTMLAttributes<HTMLTableCellElement> & Align & { label?: string }) {
  return <td data-label={label} className={cx(numeric && s.num, actions && s.actions, className)} {...rest} />;
}
export function Tr(props: HTMLAttributes<HTMLTableRowElement>) {
  return <tr {...props} />;
}
