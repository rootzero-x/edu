"use client";

import { useEffect, useRef, useState } from "react";
import { CheckCircle2, Send } from "lucide-react";
import { Button, Checkbox, Field, Input, Select, Textarea } from "@/components/ui";
import s from "./landing.module.css";

type Texts = Record<string, string>;
const API = process.env.NEXT_PUBLIC_API_URL ?? "";

/**
 * "Bepul sinov darsiga yoziling" — ariza formasi. Spamdan himoya serverda: limit, yashirin maydon, to'ldirish vaqti.
 * Barcha matnlar sozlamalardan (leads.*).
 */
export default function LeadForm({ labels, consentText, consentRequired, successText, courses }: {
  labels: Texts; consentText: string; consentRequired: boolean; successText: string; courses: { id: number; title: string }[];
}) {
  const t = (k: string, f: string) => labels[k] || f;
  const started = useRef(Date.now());
  const [form, setForm] = useState({ name: "", phone: "", course_id: "", comment: "", consent: false, website: "" });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const [fail, setFail] = useState<string | null>(null);
  // Kurs kartochkasidagi "Yozilish" (#ariza?course=ID) — kurs oldindan tanlanadi.
  useEffect(() => {
    const pick = () => {
      const m = /course=(\d+)/.exec(window.location.hash);
      if (m) setForm((f) => ({ ...f, course_id: m[1] }));
    };
    pick();
    window.addEventListener("hashchange", pick);
    return () => window.removeEventListener("hashchange", pick);
  }, []);

  function validate() {
    const e: Record<string, string> = {};
    if (form.name.trim().length < 2) e.name = t("name_required", "Ismingizni kiriting");
    const digits = form.phone.replace(/\D/g, "");
    if (!(digits.length === 9 || (digits.length === 12 && digits.startsWith("998")) || (digits.length === 10 && digits.startsWith("8")))) e.phone = t("phone_invalid", "Telefon raqamini to'liq kiriting");
    if (consentRequired && !form.consent) e.consent = t("consent_required", "Rozilik belgisini qo'ying");
    setErrors(e);
    return Object.keys(e).length === 0;
  }

  async function send(url: string, body: string) {
    const res = await fetch(url, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body });
    return { status: res.status, data: await res.json().catch(() => ({})) };
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setFail(null);
    if (!validate()) return;
    setBusy(true);
    const utm = Object.fromEntries(["source", "medium", "campaign"].map((k) => [k, new URLSearchParams(window.location.search).get(`utm_${k}`) ?? ""]).filter(([, v]) => v));
    const body = JSON.stringify({
      name: form.name.trim(), phone: form.phone, course_id: form.course_id ? Number(form.course_id) : null, comment: form.comment.trim() || null,
      consent: form.consent, website: form.website, fill_ms: Date.now() - started.current, utm: Object.keys(utm).length ? utm : undefined,
    });
    let r: { status: number; data: { message?: string; errors?: Record<string, string[]> } } | null = null;
    try { r = API ? await send(`${API}/v1/public/leads`, body) : null; } catch { r = null; }
    if (!r) { try { r = await send("/api/public/lead", body); } catch { r = null; } }
    setBusy(false);
    if (r && r.status >= 200 && r.status < 300) { setDone(r.data.message || successText); return; }
    if (r?.status === 422 && r.data.errors) {
      setErrors(Object.fromEntries(Object.entries(r.data.errors).map(([k, v]) => [k, v[0]])));
      return;
    }
    setFail(r?.status === 429 ? "Juda ko'p urinish. Birozdan keyin qayta yuboring yoki qo'ng'iroq qiling." : t("error", "Ariza yuborilmadi. Qayta urinib ko'ring."));
  }

  if (done) return <div className={s.leadDone} role="status"><CheckCircle2 aria-hidden /><p>{done}</p></div>;

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => setForm({ ...form, [k]: e.target.value });
  return (
    <form className={s.leadForm} onSubmit={submit} noValidate>
      <div className={s.leadGrid}>
        <Field label={t("name", "Ismingiz")} required error={errors.name}><Input autoComplete="name" value={form.name} onChange={set("name")} maxLength={128} /></Field>
        <Field label={t("phone", "Telefon raqamingiz")} required error={errors.phone}>
          <Input type="tel" inputMode="tel" autoComplete="tel" placeholder={t("phone_hint", "90 123 45 67")} value={form.phone} onChange={set("phone")} maxLength={32} />
        </Field>
        {courses.length > 0 && (
          <Field label={t("course", "Qaysi kurs qiziqtiradi?")}>
            <Select value={form.course_id} onChange={set("course_id")}>
              <option value="">{t("course_any", "Hali bilmayman")}</option>
              {courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </Select>
          </Field>
        )}
        <Field label={t("comment", "Izoh (ixtiyoriy)")} className={s.leadWide}><Textarea rows={2} value={form.comment} onChange={set("comment")} maxLength={1000} /></Field>
      </div>
      {/* Odamlar ko'rmaydigan maydon: to'ldirilsa — bot. */}
      <div className={s.hp} aria-hidden>
        <label>Website<input tabIndex={-1} autoComplete="off" value={form.website} onChange={set("website")} /></label>
      </div>
      <div className={s.leadFoot}>
        <div>
          <Checkbox label={consentText} checked={form.consent} onChange={(e) => setForm({ ...form, consent: e.target.checked })} aria-invalid={errors.consent ? true : undefined} />
          {errors.consent && <p className={s.leadErr} role="alert">{errors.consent}</p>}
        </div>
        <Button type="submit" size="lg" icon={<Send />} loading={busy}>{busy ? t("sending", "Yuborilmoqda…") : t("button", "Ariza yuborish")}</Button>
      </div>
      {fail && <p className={s.leadErr} role="alert">{fail}</p>}
    </form>
  );
}
