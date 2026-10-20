import type { MetadataRoute } from "next";
import { publicSettings } from "@/lib/api";
import { str } from "@/lib/settings";

export default async function robots(): Promise<MetadataRoute.Robots> {
  const base = str(await publicSettings(), "site.url", "https://tisu.uzstudents.uz").replace(/\/$/, "");
  return {
    rules: [{ userAgent: "*", allow: "/", disallow: ["/dashboard", "/tg", "/api", "/c/"] }],
    sitemap: `${base}/sitemap.xml`,
  };
}
