"use client";

import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from "react";
import { AlertTriangle, HelpCircle } from "lucide-react";
import { Button } from "./Button";
import { Dialog } from "./Dialog";
import { Field, Input, Select, Textarea } from "./Field";

export type ConfirmOptions = {
  title: string;
  description?: ReactNode;
  confirmText?: string;
  cancelText?: string;
  tone?: "default" | "danger";
};

export type PromptField = {
  name: string;
  label: string;
  type?: "text" | "url" | "number" | "tel" | "password" | "date" | "time" | "textarea" | "select";
  defaultValue?: string;
  placeholder?: string;
  hint?: string;
  required?: boolean;
  options?: { value: string; label: string }[];
  autoComplete?: string;
};

export type PromptOptions = {
  title: string;
  description?: ReactNode;
  fields: PromptField[];
  submitText?: string;
  /** Qiymatlarni tekshirish yoki saqlash. Xato matni qaytarsa — oyna yopilmaydi. */
  onSubmit?: (values: Record<string, string>) => Promise<string | null | void> | string | null | void;
};

type State =
  | { kind: "confirm"; opts: ConfirmOptions; resolve: (v: boolean) => void }
  | { kind: "prompt"; opts: PromptOptions; resolve: (v: Record<string, string> | null) => void };

const Ctx = createContext<{ confirm: (o: ConfirmOptions) => Promise<boolean>; prompt: (o: PromptOptions) => Promise<Record<string, string> | null> } | null>(null);

/**
 * window.confirm / window.prompt o'rniga: `await confirm({...})`, `await prompt({fields})`.
 * Har qanday sahifa (testlar, sertifikatlar …) ham ishlata oladi.
 */
export function DialogsProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<State | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const current = useRef<State | null>(null);

  const confirm = useCallback((opts: ConfirmOptions) => new Promise<boolean>((resolve) => {
    const st: State = { kind: "confirm", opts, resolve };
    current.current = st;
    setState(st);
  }), []);

  const prompt = useCallback((opts: PromptOptions) => new Promise<Record<string, string> | null>((resolve) => {
    const st: State = { kind: "prompt", opts, resolve };
    current.current = st;
    setValues(Object.fromEntries(opts.fields.map((f) => [f.name, f.defaultValue ?? ""])));
    setError(null);
    setState(st);
  }), []);

  const close = (result: boolean | Record<string, string> | null) => {
    const st = current.current;
    current.current = null;
    setState(null);
    setBusy(false);
    if (!st) return;
    if (st.kind === "confirm") st.resolve(Boolean(result));
    else st.resolve(result && typeof result === "object" ? result : null);
  };

  async function submitPrompt() {
    if (state?.kind !== "prompt") return;
    const trimmed = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v.trim()]));
    if (state.opts.onSubmit) {
      setBusy(true);
      setError(null);
      const err = await state.opts.onSubmit(trimmed);
      setBusy(false);
      if (err) return setError(err);
    }
    close(trimmed);
  }

  return (
    <Ctx.Provider value={{ confirm, prompt }}>
      {children}
      {state?.kind === "confirm" && (
        <Dialog
          open
          onClose={() => close(false)}
          title={state.opts.title}
          description={state.opts.description}
          tone={state.opts.tone}
          icon={state.opts.tone === "danger" ? <AlertTriangle /> : <HelpCircle />}
          width={440}
          footer={
            <>
              <Button variant="secondary" onClick={() => close(false)}>{state.opts.cancelText ?? "Bekor qilish"}</Button>
              <Button variant={state.opts.tone === "danger" ? "danger" : "primary"} onClick={() => close(true)} autoFocus>
                {state.opts.confirmText ?? "Tasdiqlash"}
              </Button>
            </>
          }
        />
      )}
      {state?.kind === "prompt" && (
        <Dialog
          open
          onClose={() => close(null)}
          title={state.opts.title}
          description={state.opts.description}
          onSubmit={submitPrompt}
          width={480}
          footer={
            <>
              <Button variant="secondary" onClick={() => close(null)} disabled={busy}>Bekor qilish</Button>
              <Button type="submit" loading={busy}>{state.opts.submitText ?? "Saqlash"}</Button>
            </>
          }
        >
          {state.opts.fields.map((f, i) => {
            const common = {
              name: f.name,
              value: values[f.name] ?? "",
              placeholder: f.placeholder,
              autoFocus: i === 0,
              autoComplete: f.autoComplete,
              onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => setValues((v) => ({ ...v, [f.name]: e.target.value })),
            };
            return (
              <Field key={f.name} label={f.label} hint={f.hint} required={f.required}>
                {f.type === "textarea" ? <Textarea rows={4} {...common} />
                  : f.type === "select" ? (
                    <Select {...common}>
                      {!f.required && <option value="">—</option>}
                      {f.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </Select>
                  ) : <Input type={f.type ?? "text"} inputMode={f.type === "number" ? "numeric" : undefined} {...common} />}
              </Field>
            );
          })}
          {error && <p className="error" role="alert" style={{ margin: 0 }}>{error}</p>}
        </Dialog>
      )}
    </Ctx.Provider>
  );
}

function useDialogs() {
  const ctx = useContext(Ctx);
  if (!ctx) throw new Error("DialogsProvider topilmadi");
  return ctx;
}

export const useConfirm = () => useDialogs().confirm;
export const usePrompt = () => useDialogs().prompt;
