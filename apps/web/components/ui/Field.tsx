import { cloneElement, forwardRef, isValidElement, useId, type InputHTMLAttributes, type ReactElement, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from "react";
import s from "./Field.module.css";
import { cx } from "./cx";

type FieldProps = {
  label?: ReactNode;
  hint?: ReactNode;
  error?: string | null;
  required?: boolean;
  className?: string;
  /** Bitta boshqaruv elementi (Input, Select, Textarea …). id va aria-* avtomatik ulanadi. */
  children: ReactElement<Record<string, unknown>>;
};

/** Yorliq + boshqaruv + izoh/xato. Accessibility bog'lanishlarini o'zi qo'yadi. */
export function Field({ label, hint, error, required, className, children }: FieldProps) {
  const auto = useId();
  const id = (children.props.id as string | undefined) ?? auto;
  const hintId = hint ? `${id}-hint` : undefined;
  const errId = error ? `${id}-err` : undefined;
  const describedBy = [hintId, errId].filter(Boolean).join(" ") || undefined;
  return (
    <div className={cx(s.field, className)}>
      {label && (
        <label htmlFor={id} className={s.label}>
          {label}
          {required && <span className={s.req} aria-hidden>*</span>}
        </label>
      )}
      {isValidElement(children)
        ? cloneElement(children, { id, required: required || children.props.required, "aria-invalid": error ? true : undefined, "aria-describedby": describedBy })
        : children}
      {hint && !error && <p id={hintId} className={s.hint}>{hint}</p>}
      {error && <p id={errId} className={s.err} role="alert">{error}</p>}
    </div>
  );
}

type Size = { size?: "sm" | "md"; leading?: ReactNode };

export const Input = forwardRef<HTMLInputElement, Omit<InputHTMLAttributes<HTMLInputElement>, "size"> & Size>(function Input({ className, size, leading, ...rest }, ref) {
  const el = <input ref={ref} className={cx(s.control, size === "sm" && s.sm, className)} {...rest} />;
  if (!leading) return el;
  return <div className={s.wrap}><span className={s.lead} aria-hidden>{leading}</span>{el}</div>;
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(function Textarea({ className, ...rest }, ref) {
  return <textarea ref={ref} className={cx(s.control, className)} {...rest} />;
});

export const Select = forwardRef<HTMLSelectElement, Omit<SelectHTMLAttributes<HTMLSelectElement>, "size"> & { size?: "sm" | "md" }>(function Select({ className, size, ...rest }, ref) {
  return <select ref={ref} className={cx(s.control, s.select, size === "sm" && s.sm, className)} {...rest} />;
});

export function Checkbox({ label, className, ...rest }: InputHTMLAttributes<HTMLInputElement> & { label?: ReactNode }) {
  return (
    <label className={cx(s.check, className)}>
      <input type="checkbox" {...rest} />
      {label}
    </label>
  );
}

/** Yoqish/o'chirish. role="switch" bilan. */
export function Switch({ label, className, ...rest }: Omit<InputHTMLAttributes<HTMLInputElement>, "type"> & { label?: ReactNode }) {
  return (
    <label className={cx(s.switch, className)}>
      <input type="checkbox" role="switch" aria-checked={Boolean(rest.checked)} {...rest} />
      <span className={s.track} aria-hidden />
      {label}
    </label>
  );
}
