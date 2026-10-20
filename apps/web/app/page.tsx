import Link from "next/link";
import { API_URL, publicSettings } from "@/lib/api";

export const revalidate = 60;

async function apiStatus(): Promise<boolean> {
  try {
    const res = await fetch(`${API_URL}/health`, { cache: "no-store", signal: AbortSignal.timeout(3000) });
    return res.ok;
  } catch {
    return false;
  }
}

export default async function Home() {
  const [s, ok] = await Promise.all([publicSettings(), apiStatus()]);
  const features = Array.isArray(s?.["site.features"]) ? s!["site.features"] : [];
  return (
    <main>
      <header className="top">
        {s?.["site.logo_url"] ? <img src={s["site.logo_url"]} alt="" className="logo" /> : null}
        <h1>{s?.["site.name"] ?? "TISU Edu"}</h1>
        <Link href="/login" className="btn">Kirish</Link>
      </header>
      <p className="lead">{s?.["site.tagline"] ?? ""}</p>
      <div className="grid">
        {features.map((f) => (
          <div className="card" key={f.title}>
            <h2>{f.title}</h2>
            <p>{f.text}</p>
          </div>
        ))}
      </div>
      <footer className="status">
        <span className="dot" style={{ background: ok ? "#16a34a" : "#dc2626" }} />
        API: {ok ? "ishlayapti" : "ulanmagan"}
        {s?.["site.contact_phone"] ? <> · {s["site.contact_phone"]}</> : null}
        {s?.["site.contact_telegram"] ? <> · {s["site.contact_telegram"]}</> : null}
        {s?.["site.address"] ? <> · {s["site.address"]}</> : null}
      </footer>
    </main>
  );
}
