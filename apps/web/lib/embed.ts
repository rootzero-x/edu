/** Video havolasini xavfsiz embed manziliga aylantirish. Faqat sozlamadagi (course.embed_hosts) saytlar. */
export function embedUrl(raw: string, allowedHosts: string[]): string | null {
  let u: URL;
  try {
    u = new URL(raw);
  } catch {
    return null;
  }
  if (u.protocol !== "https:" && u.protocol !== "http:") return null;
  const host = u.hostname.replace(/^www\./, "").replace(/^m\./, "");
  const allowed = allowedHosts.map((h) => h.replace(/^www\./, ""));
  if (!allowed.some((h) => host === h || host.endsWith(`.${h}`))) return null;

  if (host === "youtu.be") return yt(u.pathname.slice(1));
  if (host.endsWith("youtube.com")) {
    if (u.pathname === "/watch") return yt(u.searchParams.get("v"));
    const m = u.pathname.match(/^\/(?:embed|shorts|live)\/([\w-]{6,})/);
    return m ? yt(m[1]) : null;
  }
  if (host.endsWith("vimeo.com")) {
    const m = u.pathname.match(/(\d{5,})/);
    return m ? `https://player.vimeo.com/video/${m[1]}` : null;
  }
  return null;
}

function yt(id: string | null): string | null {
  return id && /^[\w-]{6,}$/.test(id) ? `https://www.youtube-nocookie.com/embed/${id}` : null;
}
