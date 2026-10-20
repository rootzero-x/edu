import type { MetadataRoute } from "next";
import { publicSettings } from "@/lib/api";
import { siteName, str } from "@/lib/settings";

export default async function manifest(): Promise<MetadataRoute.Manifest> {
  const s = await publicSettings();
  const color = /^#[0-9a-fA-F]{3,8}$/.test(str(s, "site.primary_color")) ? str(s, "site.primary_color") : "#2563eb";
  const logo = str(s, "site.logo_url");
  return {
    name: siteName(s),
    short_name: siteName(s),
    description: str(s, "site.tagline"),
    start_url: "/dashboard",
    display: "standalone",
    background_color: "#ffffff",
    theme_color: color,
    icons: logo ? [{ src: logo, sizes: "any" }] : [{ src: "/icon.svg", sizes: "any", type: "image/svg+xml" }],
  };
}
