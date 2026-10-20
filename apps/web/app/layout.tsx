import type { Metadata } from "next";
import { publicSettings } from "@/lib/api";
import "./globals.css";

export async function generateMetadata(): Promise<Metadata> {
  const s = await publicSettings();
  return { title: s?.["site.name"] ?? "TISU Edu", description: s?.["site.description"] ?? "" };
}

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const s = await publicSettings();
  const accent = /^#[0-9a-fA-F]{3,8}$/.test(String(s?.["site.primary_color"] ?? "")) ? String(s!["site.primary_color"]) : undefined;
  return (
    <html lang={String(s?.["site.default_locale"] ?? "uz")}>
      <body style={accent ? ({ "--accent": accent } as React.CSSProperties) : undefined}>{children}</body>
    </html>
  );
}
