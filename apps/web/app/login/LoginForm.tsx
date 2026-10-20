"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";

export default function LoginForm({ telegramEnabled }: { telegramEnabled: boolean }) {
  const router = useRouter();
  const next = useSearchParams().get("next") || "/dashboard";
  const [phone, setPhone] = useState("+998");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [tgWaiting, setTgWaiting] = useState(false);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => () => { if (timer.current) clearInterval(timer.current); }, []);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    const res = await fetch("/api/auth/login", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ phone, password }) });
    setBusy(false);
    if (res.ok) return router.replace(next);
    const data = await res.json().catch(() => ({}));
    setError(data.message ?? "Kirib bo'lmadi.");
  }

  async function telegram() {
    setError(null);
    const res = await fetch("/api/auth/telegram/start", { method: "POST" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) return setError(data.message ?? "Telegram orqali kirish hozir ishlamayapti.");
    window.open(data.bot_url, "_blank", "noopener");
    setTgWaiting(true);
    const deadline = Date.now() + data.expires_in * 1000;
    timer.current = setInterval(async () => {
      if (Date.now() > deadline) {
        clearInterval(timer.current!);
        setTgWaiting(false);
        return setError("Vaqt tugadi. Qaytadan urinib ko'ring.");
      }
      const p = await fetch("/api/auth/telegram/poll", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ code: data.code, poll_secret: data.poll_secret }),
      });
      const r = await p.json().catch(() => ({}));
      if (r.status === "ok") {
        clearInterval(timer.current!);
        router.replace(next);
      } else if (r.status === "expired" || !p.ok) {
        clearInterval(timer.current!);
        setTgWaiting(false);
        setError("Havola eskirdi. Qaytadan urinib ko'ring.");
      }
    }, 2000);
  }

  return (
    <div className="card form">
      {telegramEnabled && (
        <>
          <button type="button" className="btn tg" onClick={telegram} disabled={tgWaiting}>
            {tgWaiting ? "Telegram'da tasdiqlang…" : "Telegram orqali kirish"}
          </button>
          <div className="sep">yoki</div>
        </>
      )}
      <form onSubmit={submit}>
        <label>Telefon<input value={phone} onChange={(e) => setPhone(e.target.value)} inputMode="tel" autoComplete="username" required /></label>
        <label>Parol<input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" required /></label>
        <button className="btn" disabled={busy}>{busy ? "…" : "Kirish"}</button>
      </form>
      {error && <p className="error">{error}</p>}
    </div>
  );
}
