"use client";

import { useEffect, useState } from "react";
import { ArrowLeft, CheckCircle2, Wallet } from "lucide-react";
import { Button, Dialog, Field, Input, Select, Textarea, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { billingLabels, fmtYmd, isoDateIn, type BillingEntry } from "@/lib/billing";
import { call, errorText } from "@/lib/client";
import { num, timezone } from "@/lib/settings";

type Mode = "payment" | "adjustment";

const newRequestId = () =>
  typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

/**
 * "400 000" / "400,000" / "400.000" / "400000" → 400000. So'm butun: "150000.00" → 150000,
 * "150 000,50" kabi tiyinli summa rad etiladi (NaN) — oldin ajratuvchilar olib tashlanib 15 000 050 bo'lib qolardi.
 */
const parseAmount = (v: string) => {
  let s = v.trim();
  const frac = s.match(/[.,](\d{1,2})$/);
  if (frac) {
    if (/[1-9]/.test(frac[1])) return NaN;
    s = s.slice(0, -frac[0].length);
  }
  s = s.replace(/[\s,.'’]/g, "");
  return /^\d+$/.test(s) ? Number(s) : NaN;
};

/**
 * To'lov qabul qilish yoki tuzatish kiritish: 1) forma → 2) tasdiqlash (summa, usul, yangi balans) → saqlash.
 * Har bir oyna ochilishida yangi request_id: ikki marta bosilsa ham server bitta yozuv yaratadi.
 */
export default function EntryDialog({ mode, open, onClose, student, balance, onDone }: {
  mode: Mode;
  open: boolean;
  onClose: () => void;
  student: { id: number; name: string };
  balance?: number | null;
  onDone: (r: { entry: BillingEntry; balance: number }) => void;
}) {
  const st = useSiteSettings();
  const L = billingLabels(st);
  const toast = useToast();
  const tz = timezone(st);
  const today = isoDateIn(new Date(), tz);
  const minDate = isoDateIn(new Date(Date.now() - num(st, "billing.max_backdate_days", 31) * 86400000), tz);
  const methods = Object.entries(L.methods);
  const blank = { amount: "", method: methods[0]?.[0] ?? "CASH", date: today, note: "", direction: "+" };
  const [f, setF] = useState(blank);
  const [step, setStep] = useState<"form" | "confirm">("form");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [requestId, setRequestId] = useState(newRequestId);

  useEffect(() => {
    if (open) {
      setF(blank);
      setStep("form");
      setError(null);
      setRequestId(newRequestId());
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const amount = parseAmount(f.amount);
  const signed = mode === "adjustment" && f.direction === "-" ? -amount : amount;
  const max = num(st, "billing.max_payment", 20000000);
  const formError = (() => {
    if (!Number.isFinite(amount) || amount <= 0) return "Summani butun son bilan kiriting.";
    if (amount > max) return `Summa ${L.money(max)} dan oshmasin.`;
    if (!f.date || f.date > today || f.date < minDate) return `Sana ${fmtYmd(minDate)} – ${fmtYmd(today)} oralig'ida bo'lsin.`;
    if (mode === "adjustment" && f.note.trim().length < 3) return "Sababni yozing (kamida 3 belgi).";
    return null;
  })();

  async function save() {
    setBusy(true);
    setError(null);
    const r = mode === "payment"
      ? await call<{ entry: BillingEntry; balance: number }>("POST", `billing/students/${student.id}/payments`, {
        amount, method: f.method, date: f.date, note: f.note.trim() || null, request_id: requestId,
      })
      : await call<{ entry: BillingEntry; balance: number }>("POST", `billing/students/${student.id}/adjustments`, {
        amount: signed, reason: f.note.trim(), date: f.date, request_id: requestId,
      });
    setBusy(false);
    if (!r.ok) {
      setError(errorText(r.data));
      setStep("form");
      return;
    }
    toast.success(mode === "payment" ? "To'lov saqlandi" : "Tuzatish saqlandi", `${student.name} · ${L.signed(signed)}`);
    onDone(r.data);
    onClose();
  }

  const title = mode === "payment" ? "To'lov qabul qilish" : "Tuzatish / chegirma";
  const after = balance == null || !Number.isFinite(signed) ? null : balance + signed;

  return (
    <Dialog
      open={open}
      onClose={busy ? () => {} : onClose}
      title={step === "form" ? title : "Tasdiqlang"}
      description={student.name}
      icon={step === "form" ? <Wallet /> : <CheckCircle2 />}
      onSubmit={() => (step === "form" ? !formError && setStep("confirm") : !busy && save())}
      footer={step === "form" ? (
        <>
          <Button type="button" variant="ghost" onClick={onClose}>Bekor qilish</Button>
          <Button type="submit" disabled={Boolean(formError)}>Davom etish</Button>
        </>
      ) : (
        <>
          <Button type="button" variant="ghost" icon={<ArrowLeft />} onClick={() => setStep("form")} disabled={busy}>Orqaga</Button>
          <Button type="submit" loading={busy}>Tasdiqlash va saqlash</Button>
        </>
      )}
    >
      {step === "form" ? (
        <div style={{ display: "grid", gap: 14 }}>
          {mode === "adjustment" && (
            <Field label="Turi" required>
              <Select value={f.direction} onChange={(e) => setF({ ...f, direction: e.target.value })}>
                <option value="+">Balansga qo&apos;shish (chegirma, qaytarish)</option>
                <option value="-">Balansdan ayirish (qo&apos;shimcha hisob)</option>
              </Select>
            </Field>
          )}
          <Field label={`Summa (${L.currency})`} required hint={Number.isFinite(amount) && amount > 0 ? L.money(amount) : undefined}>
            <Input inputMode="numeric" autoComplete="off" autoFocus placeholder="400 000" value={f.amount}
              onChange={(e) => setF({ ...f, amount: e.target.value })} />
          </Field>
          {mode === "payment" && (
            <Field label="To'lov usuli" required>
              <Select value={f.method} onChange={(e) => setF({ ...f, method: e.target.value })}>
                {methods.map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </Select>
            </Field>
          )}
          <Field label="Sana" required>
            <Input type="date" min={minDate} max={today} value={f.date} onChange={(e) => setF({ ...f, date: e.target.value })} />
          </Field>
          <Field label={mode === "payment" ? "Izoh" : "Sabab"} required={mode === "adjustment"}>
            <Textarea rows={2} maxLength={500} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })}
              placeholder={mode === "payment" ? "Masalan: oktabr oyi uchun" : "Masalan: aka-uka chegirmasi"} />
          </Field>
          {(error || (f.amount && formError)) && <p className="error" role="alert" style={{ margin: 0 }}>{error ?? formError}</p>}
        </div>
      ) : (
        <dl style={{ display: "grid", gridTemplateColumns: "auto 1fr", gap: "8px 16px", margin: 0 }}>
          <dt className="muted">O&apos;quvchi</dt><dd style={{ margin: 0 }}><b>{student.name}</b></dd>
          <dt className="muted">Summa</dt><dd style={{ margin: 0, fontSize: "1.25rem", fontWeight: 700 }}>{L.signed(signed)}</dd>
          {mode === "payment" && <><dt className="muted">Usul</dt><dd style={{ margin: 0 }}>{L.methods[f.method] ?? f.method}</dd></>}
          <dt className="muted">Sana</dt><dd style={{ margin: 0 }}>{fmtYmd(f.date)}</dd>
          {f.note.trim() && <><dt className="muted">{mode === "payment" ? "Izoh" : "Sabab"}</dt><dd style={{ margin: 0, overflowWrap: "anywhere" }}>{f.note.trim()}</dd></>}
          {after != null && balance != null && (
            <><dt className="muted">Balans</dt><dd style={{ margin: 0 }}>{L.money(balance)} → <b>{L.money(after)}</b></dd></>
          )}
        </dl>
      )}
    </Dialog>
  );
}
