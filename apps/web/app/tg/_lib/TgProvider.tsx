"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { setReauth } from "./api";
import { haptic, openLink, waitForWebApp, webApp, type WebApp } from "./webapp";

export type TgUser = {
  id: number;
  phone: string;
  first_name: string;
  last_name: string | null;
  role: string;
  permissions: string[];
};

type Phase = "boot" | "outside" | "need_phone" | "linking" | "blocked" | "error" | "ready";

type Ctx = {
  me: TgUser;
  settings: Record<string, any>;
  t: (key: string, vars?: Record<string, string | number>) => string;
  can: (permission: string) => boolean;
  tz: string;
};

const TgContext = createContext<Ctx | null>(null);

export function useTg(): Ctx {
  const ctx = useContext(TgContext);
  if (!ctx) throw new Error("useTg TgProvider ichida ishlatiladi");
  return ctx;
}

/** Sozlamalar yuklanmasa ham ko'rinadigan minimal matnlar (asosiylari miniapp.texts sozlamasida). */
const FALLBACK: Record<string, string> = {
  loading: "Yuklanmoqda…",
  error: "Xatolik yuz berdi.",
  retry: "Qayta urinish",
  nav_news: "Yangiliklar",
  news_empty: "Hozircha yangiliklar yo'q.",
  news_new: "Yangi",
  news_pinned: "Muhim",
  open_in_telegram: "Bu sahifa Telegram ichida ochiladi.",
  go_to_bot: "Botga o'tish",
};

export default function TgProvider({ settings, children }: { settings: Record<string, any> | null; children: React.ReactNode }) {
  const s = settings ?? {};
  const texts = { ...FALLBACK, ...((s["miniapp.texts"] as Record<string, string>) ?? {}) };
  const t = useCallback(
    (key: string, vars: Record<string, string | number> = {}) =>
      Object.entries(vars).reduce((acc, [k, v]) => acc.replaceAll(`{${k}}`, String(v)), texts[key] ?? key),
    [settings],
  );
  const [phase, setPhase] = useState<Phase>("boot");
  const [me, setMe] = useState<TgUser | null>(null);
  const waRef = useRef<WebApp | null>(null);
  const router = useRouter();
  const pathname = usePathname();
  const bot = String(s["telegram.bot_username"] ?? "").replace(/^@/, "");

  /** initData bilan kirish. true — tayyor. */
  const auth = useCallback(async (): Promise<boolean> => {
    const wa = waRef.current;
    if (!wa?.initData) {
      setPhase("outside");
      return false;
    }
    try {
      const res = await fetch("/api/auth/telegram/miniapp", {
        method: "POST",
        headers: { "Content-Type": "application/json", "x-tisu-tg": "1" },
        body: JSON.stringify({ init_data: wa.initData }),
      });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data.status === "ok") {
        setMe(data.user);
        setPhase("ready");
        return true;
      }
      setPhase(data.status === "need_phone" ? "need_phone" : data.status === "blocked" || res.status === 403 ? "blocked" : "error");
    } catch {
      setPhase("error");
    }
    return false;
  }, []);

  useEffect(() => {
    setReauth(auth);
    let cancelled = false;
    (async () => {
      const wa = await waitForWebApp();
      if (cancelled) return;
      waRef.current = wa;
      if (!wa) return setPhase("outside");
      wa.ready();
      wa.expand();
      const applyTheme = () => {
        document.documentElement.dataset.tgScheme = wa.colorScheme;
        try {
          wa.setHeaderColor("bg_color");
          wa.setBackgroundColor("bg_color");
        } catch {
          /* eski versiya */
        }
      };
      applyTheme();
      wa.onEvent("themeChanged", applyTheme);
      await auth();
    })();
    return () => {
      cancelled = true;
    };
  }, [auth]);

  // BackButton: bosh sahifadan boshqa joyda ko'rinadi.
  useEffect(() => {
    const wa = webApp();
    if (!wa || phase !== "ready") return;
    const back = () => {
      haptic.tap();
      if (window.history.length > 1) router.back();
      else router.push("/tg");
    };
    if (pathname !== "/tg") {
      wa.BackButton.show();
      wa.BackButton.onClick(back);
    } else wa.BackButton.hide();
    return () => wa.BackButton.offClick(back);
  }, [pathname, phase, router]);

  /** Telefon ulash: requestContact → bot kontaktni qabul qiladi → qayta kirish. */
  async function sharePhone() {
    const wa = waRef.current;
    haptic.tap();
    if (!wa || !wa.isVersionAtLeast("6.9")) return goToBot();
    wa.requestContact(async (ok) => {
      if (!ok) return;
      setPhase("linking");
      for (let i = 0; i < 10; i++) {
        await new Promise((r) => setTimeout(r, 1500));
        if (await auth()) return haptic.success();
      }
      setPhase("need_phone");
    });
  }

  function goToBot() {
    if (!bot) return;
    openLink(`https://t.me/${bot}?start=miniapp`);
    waRef.current?.close();
  }

  if (phase !== "ready" || !me) {
    return (
      <div className="tg-center">
        {(phase === "boot" || phase === "linking") && (
          <>
            <div className="tg-spinner" />
            <p className="tg-hint">{t(phase === "linking" ? "waiting_link" : "loading")}</p>
          </>
        )}
        {phase === "need_phone" && (
          <>
            <div className="tg-emoji">📱</div>
            <h2>{t("need_phone_title")}</h2>
            <p className="tg-hint">{t("need_phone_text")}</p>
            <button className="tg-btn" onClick={sharePhone}>{t("share_phone")}</button>
            {bot && <button className="tg-btn ghost" onClick={goToBot}>{t("go_to_bot")}</button>}
          </>
        )}
        {phase === "outside" && (
          <>
            <div className="tg-emoji">✈️</div>
            <p className="tg-hint">{t("open_in_telegram")}</p>
            {bot && <a className="tg-btn" href={`https://t.me/${bot}`}>{t("go_to_bot")}</a>}
          </>
        )}
        {phase === "blocked" && <p className="tg-hint">{t("blocked")}</p>}
        {phase === "error" && (
          <>
            <p className="tg-hint">{t("error")}</p>
            <button className="tg-btn" onClick={() => { setPhase("boot"); auth(); }}>{t("retry")}</button>
          </>
        )}
      </div>
    );
  }

  const ctx: Ctx = {
    me,
    settings: s,
    t,
    can: (p) => me.permissions.includes(p),
    tz: String(s["app.timezone"] ?? "Asia/Tashkent"),
  };
  return <TgContext.Provider value={ctx}>{children}</TgContext.Provider>;
}
