import type { Metadata, Viewport } from "next";
import Script from "next/script";
import { publicSettings } from "@/lib/api";
import TgProvider from "./_lib/TgProvider";
import "./tg.css";

export async function generateMetadata(): Promise<Metadata> {
  const s = await publicSettings();
  return { title: s?.["site.name"] ?? "TISU Edu", robots: { index: false } };
}

export const viewport: Viewport = { width: "device-width", initialScale: 1, maximumScale: 1, userScalable: false, viewportFit: "cover" };

/** Telegram Mini App. Ranglar Telegram mavzusidan (--tg-theme-*), zaxira — sayt rangi. */
export default async function TgLayout({ children }: { children: React.ReactNode }) {
  const s = await publicSettings();
  const accent = /^#[0-9a-fA-F]{3,8}$/.test(String(s?.["site.primary_color"] ?? "")) ? String(s!["site.primary_color"]) : undefined;
  return (
    <div className="tgapp" style={accent ? ({ "--tg-accent": accent } as React.CSSProperties) : undefined}>
      <Script src="https://telegram.org/js/telegram-web-app.js?59" strategy="afterInteractive" />
      <TgProvider settings={s}>{children}</TgProvider>
    </div>
  );
}
