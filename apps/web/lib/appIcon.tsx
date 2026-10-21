import { ImageResponse } from "next/og";
import { publicSettings } from "@/lib/api";
import { accentColors, str } from "@/lib/settings";

/** Ilova ikonkasi (PNG): sayt rangi va bitiruvchi qalpoq belgisi — iOS/Android'ga o'rnatish uchun. */
export async function appIcon(size: number, maskable = false) {
  const s = await publicSettings();
  const accent = accentColors(str(s, "site.primary_color"))?.accent ?? "#2563eb";

  // Maskable: platforma burchaklarni o'zi kesadi — fon to'liq, harf xavfsiz zonada (80%).
  return new ImageResponse(
    (
      <div style={{ width: "100%", height: "100%", display: "flex", alignItems: "center", justifyContent: "center", background: accent,
        borderRadius: maskable ? 0 : size * 0.22 }}>
        {/* app/icon.svg dagi bilan bir xil bitiruvchi qalpoq belgisi */}
        <svg width={size * (maskable ? 0.72 : 0.9)} height={size * (maskable ? 0.72 : 0.9)} viewBox="0 0 64 64">
          <path d="M32 14 6 26l26 12 20-9.2V42h4V26L32 14z" fill="#fff" />
          <path d="M18 36v8c0 3.3 6.3 6 14 6s14-2.7 14-6v-8l-14 6.4L18 36z" fill="#fff" opacity="0.85" />
        </svg>
      </div>
    ),
    { width: size, height: size },
  );
}
