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
  isVisible: boolean;
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
  return Promise.resolve(window.confirm(message));
}

export function openLink(url: string) {
  const wa = webApp();
  if (wa) {
    if (/^https:\/\/t\.me\//.test(url)) wa.openTelegramLink(url);
    else wa.openLink(url);
  } else window.open(url, "_blank", "noopener");
}

/** SDK yuklanishini kutish (layout'da afterInteractive). */
export function waitForWebApp(timeoutMs = 4000): Promise<WebApp | null> {
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
