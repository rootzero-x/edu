"use client";

/** Telegram WebApp SDK (telegram-web-app.js) — ishlatiladigan qismi uchun turlar. */
type BottomButton = {
  setText(text: string): BottomButton;
  show(): BottomButton;
  hide(): BottomButton;
  enable(): BottomButton;
  disable(): BottomButton;
  showProgress(leaveActive?: boolean): BottomButton;
  hideProgress(): BottomButton;
  onClick(cb: () => void): BottomButton;
  offClick(cb: () => void): BottomButton;
  setParams(p: { color?: string; text_color?: string; is_active?: boolean; is_visible?: boolean }): BottomButton;
  isVisible: boolean;
  isActive: boolean;
};

export type WebApp = {
  initData: string;
  initDataUnsafe: { user?: { id: number; first_name: string; language_code?: string }; start_param?: string };
  version: string;
  platform: string;
  colorScheme: "light" | "dark";
  themeParams: Record<string, string>;
  isVersionAtLeast(v: string): boolean;
  ready(): void;
  expand(): void;
  close(): void;
  setHeaderColor(color: string): void;
  setBackgroundColor(color: string): void;
  enableClosingConfirmation(): void;
  disableClosingConfirmation(): void;
  onEvent(event: string, cb: (...args: any[]) => void): void;
  offEvent(event: string, cb: (...args: any[]) => void): void;
  openLink(url: string): void;
  openTelegramLink(url: string): void;
  showAlert(message: string, cb?: () => void): void;
  showConfirm(message: string, cb: (ok: boolean) => void): void;
  requestContact(cb?: (ok: boolean) => void): void;
  BackButton: { show(): void; hide(): void; onClick(cb: () => void): void; offClick(cb: () => void): void };
  MainButton: BottomButton;
  HapticFeedback: {
    impactOccurred(style: "light" | "medium" | "heavy" | "rigid" | "soft"): void;
    notificationOccurred(type: "error" | "success" | "warning"): void;
    selectionChanged(): void;
  };
};

declare global {
  interface Window {
    Telegram?: { WebApp?: WebApp };
  }
}

export function webApp(): WebApp | null {
  return typeof window !== "undefined" ? window.Telegram?.WebApp ?? null : null;
}

/** Eski klientlarda mavjud bo'lmagan metodlar xato bermasin. */
function safe(fn: () => void) {
  try {
    fn();
  } catch {
    /* qo'llab-quvvatlanmaydi */
  }
}

export const haptic = {
  tap: () => safe(() => webApp()?.HapticFeedback.impactOccurred("light")),
  select: () => safe(() => webApp()?.HapticFeedback.selectionChanged()),
  success: () => safe(() => webApp()?.HapticFeedback.notificationOccurred("success")),
  error: () => safe(() => webApp()?.HapticFeedback.notificationOccurred("error")),
  warning: () => safe(() => webApp()?.HapticFeedback.notificationOccurred("warning")),
};

export function confirmDialog(message: string): Promise<boolean> {
  const wa = webApp();
  if (wa && wa.isVersionAtLeast("6.2")) return new Promise((resolve) => wa.showConfirm(message, resolve));
  // Eski Telegram klientida showConfirm yo'q, window.confirm esa Mini App ichida bloklanadi/osilib qoladi.
  // Foydalanuvchi tugmani o'zi bosgan — tasdiqlangan deb hisoblaymiz (aks holda test umuman topshirilmasdi).
  return Promise.resolve(true);
}

export function openLink(url: string) {
  const wa = webApp();
  if (wa) {
    if (/^https:\/\/t\.me\//.test(url)) wa.openTelegramLink(url);
    else wa.openLink(url);
  } else window.open(url, "_blank", "noopener");
}

/**
 * Sahifadan chiqishdan oldin so'rash (masalan, test yechilayotganda BackButton bosilsa).
 * false qaytarsa, orqaga o'tilmaydi.
 */
let backGuard: (() => Promise<boolean>) | null = null;

export function setBackGuard(fn: (() => Promise<boolean>) | null) {
  backGuard = fn;
}

export async function canLeave(): Promise<boolean> {
  return backGuard ? backGuard() : true;
}

/** Telegram ichida ochilganmi (SDK hali yuklanmagan bo'lsa ham — URL'dagi launch parametrlari bo'yicha). */
export function launchedFromTelegram(): boolean {
  if (typeof window === "undefined") return false;
  if (/(^|[#&?])tgWebAppData=/.test(window.location.hash)) return true;
  try {
    // SDK bu kalitni Telegram'dan tashqarida ham ("{}" ko'rinishida) yozadi — faqat initData bo'lsa hisoblanadi.
    const params = JSON.parse(window.sessionStorage.getItem("__telegram__initParams") ?? "null") as Record<string, unknown> | null;
    return Boolean(params?.tgWebAppData);
  } catch {
    return false;
  }
}

/** SDK yuklanishini kutish (layout'da sinxron yuklanadi; sekin tarmoqda — kutish uzoqroq). */
export function waitForWebApp(timeoutMs = 10000): Promise<WebApp | null> {
  return new Promise((resolve) => {
    const started = Date.now();
    const tick = () => {
      const wa = webApp();
      if (wa) return resolve(wa);
      if (Date.now() - started > timeoutMs) return resolve(null);
      setTimeout(tick, 50);
    };
    tick();
  });
}
