"use client";

import { useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { safeNext } from "@/lib/safe";
import { AlertCircle, ChevronDown, KeyRound, Phone } from "lucide-react";
import { Button, Field, Input, Tabs } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { str } from "@/lib/settings";
import TelegramAuth from "./TelegramAuth";
import s from "./login.module.css";

/** Faqat sayt ichidagi yo'llarga qaytarish (open redirect'dan himoya). */

function PasswordForm({ next }: { next: string }) {
  const router = useRouter();
  const [phone, setPhone] = useState("+998");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    const res = await fetch("/api/auth/login", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ phone, password }) }).catch(() => null);
    if (res?.ok) return router.replace(next);
    setBusy(false);
    const data = res ? await res.json().catch(() => ({})) : {};
    setError(data.message ?? "Kirib bo'lmadi.");
  }

  return (
    <form className={s.form} onSubmit={submit}>
      <Field label="Telefon" required>
        <Input value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="tel" autoComplete="username" leading={<Phone />} />
      </Field>
      <Field label="Parol" required>
        <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" leading={<KeyRound />} />
      </Field>
      {error && <div className={s.alert} role="alert"><AlertCircle aria-hidden />{error}</div>}
      <Button type="submit" size="lg" block loading={busy}>Kirish</Button>
    </form>
  );
}

export default function LoginForm({ telegramEnabled }: { telegramEnabled: boolean }) {
  const st = useSiteSettings();
  const sp = useSearchParams();
  const next = safeNext(sp.get("next"));
  const [tab, setTab] = useState(sp.get("mode") === "register" ? "register" : "login");
  const [showPassword, setShowPassword] = useState(!telegramEnabled);

  const common = {
    next,
    waitingText: str(st, "login.waiting_text", "Telegram'da kirishni tasdiqlang."),
    qrHint: str(st, "login.qr_hint"),
    successText: str(st, "login.success_text", "Tasdiqlandi!"),
  };

  if (!telegramEnabled) return <PasswordForm next={next} />;

  return (
    <>
      <Tabs
        variant="pills"
        label={str(st, "login.title")}
        value={tab}
        onChange={setTab}
        items={[
          { id: "login", label: str(st, "site.nav_login", "Kirish") },
          { id: "register", label: str(st, "login.register_title", "Ro'yxatdan o'tish") },
        ]}
      />
      <div role="tabpanel" style={{ marginTop: -4 }}>
        {tab === "login" ? (
          <TelegramAuth key="login" {...common} buttonText={str(st, "login.telegram_button", "Telegram orqali kirish")} />
        ) : (
          <TelegramAuth key="register" {...common} buttonText={str(st, "login.register_button", "Telegram orqali ro'yxatdan o'tish")} explain={str(st, "login.register_text")} />
        )}
      </div>
      {tab === "login" && (
        <div className={s.alt}>
          <div className="sep">yoki</div>
          <button type="button" className={s.altToggle} aria-expanded={showPassword} aria-controls="pw-form" onClick={() => setShowPassword((v) => !v)}>
            {str(st, "login.password_title", "Telefon va parol bilan kirish")}<ChevronDown aria-hidden />
          </button>
          {showPassword && <div id="pw-form"><PasswordForm next={next} /></div>}
        </div>
      )}
    </>
  );
}
