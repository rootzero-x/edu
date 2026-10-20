import type { MetadataRoute } from "next";
import { publicSettings } from "@/lib/api";
import { str } from "@/lib/settings";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const base = str(await publicSettings(), "site.url", "https://tisu.uzstudents.uz").replace(/\/$/, "");
  return [
    { url: `${base}/`, changeFrequency: "weekly", priority: 1 },
    { url: `${base}/login`, changeFrequency: "monthly", priority: 0.5 },
  ];
}
