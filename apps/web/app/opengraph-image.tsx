import { ImageResponse } from "next/og";
import { publicSettings } from "@/lib/api";
import { accentColors, siteName, str } from "@/lib/settings";

export const alt = "TISU Edu";
export const size = { width: 1200, height: 630 };
export const contentType = "image/png";
export const revalidate = 3600;

/** Ijtimoiy tarmoqlar (Telegram, Facebook) uchun havola rasmi — nom, shior va rang sozlamalardan. */
export default async function OpengraphImage() {
  const s = await publicSettings();
  const name = siteName(s);
  const tagline = str(s, "site.tagline");
  const accent = accentColors(str(s, "site.primary_color"))?.accent ?? "#2563eb";
  return new ImageResponse(
    (
      <div style={{ width: "100%", height: "100%", display: "flex", flexDirection: "column", justifyContent: "center", padding: "80px",
        background: `linear-gradient(135deg, ${accent} 0%, #0f172a 100%)`, color: "#fff", fontFamily: "sans-serif" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 28 }}>
          <div style={{ width: 112, height: 112, borderRadius: 28, background: "rgba(255,255,255,0.18)", display: "flex", alignItems: "center",
            justifyContent: "center", fontSize: 64, fontWeight: 800 }}>{name.slice(0, 1)}</div>
          <div style={{ fontSize: 76, fontWeight: 800, letterSpacing: -2 }}>{name}</div>
        </div>
        {tagline && <div style={{ marginTop: 40, fontSize: 40, opacity: 0.9, maxWidth: 1000 }}>{tagline}</div>}
      </div>
    ),
    size,
  );
}
