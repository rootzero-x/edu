import type { Metadata, Viewport } from "next";
import { Inter } from "next/font/google";
import { publicSettings } from "@/lib/api";
import { accentColors, siteName, str } from "@/lib/settings";
import SiteProvider from "@/components/SiteProvider";
import { themeScript } from "@/components/ThemeToggle";
import "./globals.css";

const inter = Inter({ subsets: ["latin", "cyrillic"], display: "swap", variable: "--font-inter" });

export async function generateMetadata(): Promise<Metadata> {
  const s = await publicSettings();
  const name = siteName(s);
  const logo = str(s, "site.logo_url");
  return {
    title: { default: name, template: `%s · ${name}` },
    description: str(s, "site.description"),
    icons: logo ? { icon: logo } : undefined,
    openGraph: { title: name, description: str(s, "site.description"), siteName: name },
  };
}

export async function generateViewport(): Promise<Viewport> {
  const s = await publicSettings();
  return { themeColor: accentColors(str(s, "site.primary_color"))?.accent, width: "device-width", initialScale: 1 };
}

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const s = await publicSettings();
  const c = accentColors(str(s, "site.primary_color"));
  return (
    <html
      lang={str(s, "site.default_locale", "uz")}
      className={inter.variable}
      style={c ? ({ "--accent": c.accent, "--accent-fg": c.fg } as React.CSSProperties) : undefined}
      suppressHydrationWarning
    >
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeScript }} />
      </head>
      <body>
        <SiteProvider settings={s}>{children}</SiteProvider>
      </body>
    </html>
  );
}
