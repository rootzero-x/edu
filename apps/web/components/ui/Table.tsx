import type { HTMLAttributes, TableHTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from "react";
import s from "./Surface.module.css";
import { cx } from "./cx";

/** Jadval: kichik ekranda gorizontal aylantiriladi. `caption` — ekran o'quvchilari uchun. */
export function Table({ caption, className, children, ...rest }: TableHTMLAttributes<HTMLTableElement> & { caption?: string }) {
  return (
    <div className={s.tableWrap} role="region" aria-label={caption} tabIndex={caption ? 0 : undefined}>
      <table className={cx(s.table, className)} {...rest}>
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
export function Td({ numeric, actions, className, ...rest }: TdHTMLAttributes<HTMLTableCellElement> & Align) {
  return <td className={cx(numeric && s.num, actions && s.actions, className)} {...rest} />;
}
export function Tr(props: HTMLAttributes<HTMLTableRowElement>) {
  return <tr {...props} />;
}
