"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { QRCodeSVG } from "qrcode.react";
import { AlertCircle, Check, ExternalLink, RotateCcw, Send } from "lucide-react";
import { Button } from "@/components/ui";
import s from "./login.module.css";

type Pending = { code: string; poll_secret: string; bot_url: string; deadline: number; ttl: number };
type Phase = "idle" | "starting" | "waiting" | "success" | "error";

const STORE = "tisu-tg-login";
const POLL_MS = 2000;

function load(): Pending | null {
  try {
    const p = JSON.parse(sessionStorage.getItem(STORE) ?? "null") as Pending | null;
    return p && p.deadline > Date.now() ? p : null;
  } catch {
    return null;
  }
}
function save(p: Pending | null) {
  try { p ? sessionStorage.setItem(STORE, JSON.stringify(p)) : sessionStorage.removeItem(STORE); } catch { /* ignore */ }
}

/**
 * Telegram orqali kirish / ro'yxatdan o'tish. Bot yangi foydalanuvchini o'zi yaratadi,
 * shuning uchun ikkala holat bitta oqim: kod → bot → tasdiq → poll → httpOnly cookie.
 * Token brauzer JS'iga hech qachon kelmaydi (poll route cookie'ni serverda qo'yadi).
 */
export default function TelegramAuth({ next, buttonText, explain, waitingText, qrHint, successText }: {
  next: string; buttonText: string; explain?: string; waitingText: string; qrHint: string; successText: string;
}) {
  const router = useRouter();
  const [phase, setPhase] = useState<Phase>("idle");
  const [pending, setPending] = useState<Pending | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [now, setNow] = useState(Date.now());
  const busy = useRef(false);

  const finish = useCallback((ok: boolean, message?: string) => {
    save(null);
    setPending(null);
    if (ok) {
      setPhase("success");
      setTimeout(() => { router.refresh(); router.replace(next); }, 700);
    } else {
      setPhase("error");
      setError(message ?? "Havola eskirdi. Qaytadan urinib ko'ring.");
    }
  }, [next, router]);

  const poll = useCallback(async (p: Pending) => {
    if (busy.current) return;
    if (Date.now() > p.deadline) return finish(false, "Vaqt tugadi. Qaytadan urinib ko'ring.");
    busy.current = true;
    try {
      const res = await fetch("/api/auth/telegram/poll", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ code: p.code, poll_secret: p.poll_secret }),
      });
      const r = await res.json().catch(() => ({}));
      if (r.status === "ok") finish(true);
      else if (r.status === "expired" || res.status === 404) finish(false);
      // 429/5xx — keyingi urinishda davom etadi.
    } catch {
      /* tarmoq uzildi — keyingi urinish */
    } finally {
      busy.current = false;
    }
  }, [finish]);

  // Sahifa qayta yuklansa ham (telefonda Telegram'dan qaytganda) kutishni davom ettirish.
  useEffect(() => {
    const p = load();
    if (p) { setPending(p); setPhase("waiting"); }
  }, []);

  useEffect(() => {
    if (phase !== "waiting" || !pending) return;
    const t = setInterval(() => { setNow(Date.now()); void poll(pending); }, POLL_MS);
    const tick = setInterval(() => setNow(Date.now()), 1000);
    const wake = () => { if (!document.hidden) void poll(pending); };
    document.addEventListener("visibilitychange", wake);
    window.addEventListener("focus", wake);
    return () => { clearInterval(t); clearInterval(tick); document.removeEventListener("visibilitychange", wake); window.removeEventListener("focus", wake); };
  }, [phase, pending, poll]);

  async function start() {
    setError(null);
    setPhase("starting");
    const res = await fetch("/api/auth/telegram/start", { method: "POST" }).catch(() => null);
    const data = res ? await res.json().catch(() => ({})) : {};
    if (!res?.ok || !data.bot_url) {
      setPhase("error");
      return setError(data.message ?? "Telegram orqali kirish hozir ishlamayapti.");
    }
    const p: Pending = { code: data.code, poll_secret: data.poll_secret, bot_url: data.bot_url, ttl: data.expires_in, deadline: Date.now() + data.expires_in * 1000 };
    save(p);
    setPending(p);
    setNow(Date.now());
    setPhase("waiting");
    // Telefonda — ilovani ochadi; kompyuterda — yangi oynada (QR ham ko'rsatiladi).
    window.open(p.bot_url, "_blank", "noopener");
  }

  function cancel() {
    save(null);
    setPending(null);
    setPhase("idle");
  }

  if (phase === "success") {
    return (
      <div className={s.success} role="status">
        <div className={s.check}><Check aria-hidden /></div>
        <strong>{successText}</strong>
      </div>
    );
  }

  if (phase === "waiting" && pending) {
    const left = Math.max(0, Math.round((pending.deadline - now) / 1000));
    const frac = pending.ttl > 0 ? left / pending.ttl : 0;
    const C = 2 * Math.PI * 7;
    return (
      <div className={s.waiting} aria-live="polite">
        <div className={s.qrWrap}>
          <div className={s.qr}>
            <QRCodeSVG value={pending.bot_url} size={176} level="M" bgColor="#ffffff" fgColor="#0b0f17" />
            <span className={s.qrBadge} aria-hidden><Send /></span>
          </div>
          <p className={s.qrHint} style={{ marginTop: 8 }}>{qrHint}</p>
        </div>
        <span className={s.dots} aria-hidden><i /><i /><i /></span>
        <p className={s.waitText}>{waitingText}</p>
        <span className={s.timer}>
          <svg className={s.ring} viewBox="0 0 18 18" aria-hidden>
            <circle className={s.bg} cx="9" cy="9" r="7" />
            <circle className={s.fg} cx="9" cy="9" r="7" strokeDasharray={C} strokeDashoffset={C * (1 - frac)} />
          </svg>
          <span aria-label="Qolgan vaqt">{String(Math.floor(left / 60)).padStart(2, "0")}:{String(left % 60).padStart(2, "0")}</span>
        </span>
        <div className={s.actions}>
          <a href={pending.bot_url} target="_blank" rel="noopener noreferrer" className="btn tg" style={{ minHeight: 44 }}>
            <ExternalLink size={16} aria-hidden /> Telegram
          </a>
          <Button variant="secondary" size="lg" onClick={cancel} style={{ minHeight: 44 }}>Bekor qilish</Button>
        </div>
      </div>
    );
  }

  return (
    <div className={s.tg}>
      {explain && <p className={s.explain}>{explain}</p>}
      <Button variant="telegram" size="xl" block icon={<Send />} loading={phase === "starting"} onClick={start}>{buttonText}</Button>
      {phase === "error" && error && (
        <div className={s.alert} role="alert">
          <AlertCircle aria-hidden /><span style={{ flex: 1 }}>{error}</span>
          <button type="button" className="link" onClick={start}><RotateCcw size={14} aria-hidden /> Qaytadan</button>
        </div>
      )}
    </div>
  );
}
