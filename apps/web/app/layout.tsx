import type { Metadata, Viewport } from "next";
import { Inter } from "next/font/google";
import { publicSettings } from "@/lib/api";
import { accentColors, siteName, str } from "@/lib/settings";
import SiteProvider from "@/components/SiteProvider";
import { themeScript } from "@/components/ThemeToggle";
import "./globals.css";

// Server render API (Germaniya) yoniga — Frankfurt: har API chaqiruvi okean orqali emas, ~5 ms.
export const preferredRegion = ["fra1"];

const inter = Inter({ subsets: ["latin", "cyrillic"], display: "swap", variable: "--font-inter" });

export async function generateMetadata(): Promise<Metadata> {
  const s = await publicSettings();
  const name = siteName(s);
  const logo = str(s, "site.logo_url");
  const base = str(s, "site.url", "https://tisu.uzstudents.uz").replace(/\/$/, "");
  const tagline = str(s, "site.tagline");
  return {
    metadataBase: new URL(base),
    title: { default: tagline ? `${name} — ${tagline}` : name, template: `%s · ${name}` },
    description: str(s, "site.description"),
    icons: logo ? { icon: logo, apple: logo } : undefined,
    openGraph: { title: name, description: str(s, "site.description"), siteName: name, locale: "uz_UZ", type: "website" },
    twitter: { card: "summary_large_image", title: name, description: str(s, "site.description") },
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
