"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { usePathname, useRouter } from "next/navigation";
import { setOfflineText, setReauth } from "./api";
import { canLeave, haptic, launchedFromTelegram, openLink, waitForWebApp, webApp, type WebApp } from "./webapp";

export type TgUser = {
  id: number;
  phone: string;
  first_name: string;
  last_name: string | null;
  role: string;
  permissions: string[];
};

type Phase = "boot" | "outside" | "need_phone" | "linking" | "pending" | "blocked" | "expired" | "error" | "ready";

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
  quiz_manage_on_web: "Testni tahrirlash va natijalarni ko'rish — saytdagi boshqaruv panelida.",
  open_on_web: "Saytda ochish",
  sheet_reason: "O'zgartirish sababi",
  sheet_reason_hint: "Masalan: ma'lumotnoma keltirdi",
  sheet_reason_required: "O'zgartirish sababini yozing.",
  session_expired: "Sessiya eskirgan. Ilovani yopib, qayta oching.",
  reopen: "Yopish",
  offline: "Internet bilan aloqa yo'q.",
  quiz_leave_confirm: "Test hali topshirilmagan. Chiqasizmi? Javoblar saqlanadi, keyin davom ettirishingiz mumkin.",
  go_home: "Bosh sahifa",
  draft: "Yopiq",
  lesson: "Dars",
  quiz: "Test",
  sheet_title: "Davomat varaqasi",
  attendance_groups: "Guruhlar: {n}",
  left_group: "guruhdan chiqqan",
  nav_attendance: "Davomat",
  nav_balance: "Balans",
  balance_title: "To'lovlar va balans",
  balance_empty: "To'lovlar bo'yicha ma'lumot yo'q.",
  unmarked_title: "Belgilanmagan darslar ({n})",
  staff_denied_title: "Bu kurs sizga biriktirilmagan",
  staff_denied_text: "Kurs boshqa o'qituvchiga tegishli. Kirish kerak bo'lsa, administratorga murojaat qiling.",
  account_pending: "Arizangiz administrator tasdig'ini kutmoqda. Tasdiqlangach, bot sizga xabar beradi.",
  quiz_start_confirm: "Testni boshlaysizmi? Boshlangan zahoti bitta urinish hisoblanadi va vaqt (bo'lsa) yura boshlaydi.",
};

export default function TgProvider({ settings, children }: { settings: Record<string, any> | null; children: React.ReactNode }) {
  const s = settings ?? {};
  const texts = { ...FALLBACK, ...((s["miniapp.texts"] as Record<string, string>) ?? {}) };
  setOfflineText(texts.offline);
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

  /**
   * initData bilan kirish. true — tayyor. silent — telefon ulanishini kutish paytida (polling) ekran almashmaydi;
   * natija holati qaytariladi (lastPhase), yakuniy holatlarda (pending/blocked) polling to'xtaydi.
   */
  const lastPhase = useRef<Phase>("boot");
  const auth = useCallback(async (silent = false): Promise<boolean> => {
    const wa = waRef.current;
    const show = (p: Phase) => { lastPhase.current = p; if (!silent || p === "ready") setPhase(p); };
    if (!wa?.initData) {
      show(launchedFromTelegram() ? "error" : "outside");
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
        show("ready");
        return true;
      }
      show(
        data.status === "need_phone" ? "need_phone"
          : data.status === "expired" ? "expired"
            : data.status === "pending" ? "pending"
              : data.status === "blocked" || res.status === 403 ? "blocked" : "error",
      );
    } catch {
      show("error");
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
      // SDK yuklanmadi: Telegram ichida bo'lsa — tarmoq xatosi (qayta urinish), aks holda — brauzer.
      if (!wa) return setPhase(launchedFromTelegram() ? "error" : "outside");
      wa.ready();
      wa.expand();
      const applyTheme = () => {
        document.documentElement.dataset.tgScheme = wa.colorScheme;
        try {
          // Sahifa foni secondary_bg (tg.css --c-bg): sarlavha va "overscroll" foni ham shu rangda bo'lsin.
          wa.setHeaderColor("secondary_bg_color");
          wa.setBackgroundColor("secondary_bg_color");
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
    const back = async () => {
      haptic.tap();
      if (!(await canLeave())) return;
      if (window.history.length > 1) router.back();
      else router.push("/tg");
    };
    if (pathname !== "/tg") {
      wa.BackButton.show();
      wa.BackButton.onClick(back);
    } else wa.BackButton.hide();
    return () => {
      wa.BackButton.offClick(back);
    };
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
        if (await auth(true)) return haptic.success();
        // Yakuniy javob (tasdiq kutilmoqda / bloklangan) — kutishni to'xtatib, o'sha holatni ko'rsatamiz.
        if (lastPhase.current === "pending" || lastPhase.current === "blocked") return setPhase(lastPhase.current);
      }
      setPhase("need_phone");
    });
  }

  function retry() {
    // SDK umuman yuklanmagan bo'lsa — sahifani qayta yuklash kerak.
    if (!waRef.current) return window.location.reload();
    setPhase("boot");
    auth();
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
        {phase === "pending" && (
          <>
            <div className="tg-emoji">⏳</div>
            <p className="tg-hint">{t("account_pending")}</p>
          </>
        )}
        {phase === "expired" && (
          <>
            <div className="tg-emoji">⏳</div>
            <p className="tg-hint">{t("session_expired")}</p>
            <button className="tg-btn" onClick={() => waRef.current?.close()}>{t("reopen")}</button>
          </>
        )}
        {phase === "error" && (
          <>
            <p className="tg-hint">{t("error")}</p>
            <button className="tg-btn" onClick={retry}>{t("retry")}</button>
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
