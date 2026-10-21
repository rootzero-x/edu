import type { Metadata } from "next";
import { notFound } from "next/navigation";
import QRCode from "qrcode";
import { headers } from "next/headers";
import { MONTHS, dateParts } from "@/lib/format";
import { API_URL, publicSettings } from "@/lib/api";
import PrintButton from "./PrintButton";
import "./certificate.css";

type Cert = { code: string; issued_at: string; name: string; course: string; duration_months: number;
  status?: "VALID" | "REVOKED"; revoked_at?: string | null; revoked_reason?: string | null };

async function load(code: string): Promise<Cert | null> {
  try {
    const res = await fetch(`${API_URL}/v1/public/certificates/${encodeURIComponent(code)}`, { next: { revalidate: 300 }, signal: AbortSignal.timeout(8000) });
    if (res.status === 404) return null;
    if (!res.ok) throw new Error(`API ${res.status}`);
    return ((await res.json()) as { certificate: Cert }).certificate;
  } catch (e) {
    throw new Error("Sertifikatni hozir tekshirib bo'lmadi. Birozdan so'ng qayta urinib ko'ring.", { cause: e });
  }
}

export async function generateMetadata({ params }: { params: Promise<{ code: string }> }): Promise<Metadata> {
  const { code } = await params;
  const c = await load(code);
  // Oqim boshlanishidan oldin 404 — sahifa bo'sh "qobiq" emas, to'liq "topilmadi" HTML bilan qaytadi.
  if (!c) notFound();
  return { title: !c ? "Sertifikat topilmadi" : c.status === "REVOKED" ? `Bekor qilingan sertifikat — ${c.code}` : `${c.name} — ${c.course}`, robots: { index: false } };
}

export default async function CertificatePage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const [c, s] = await Promise.all([load(code), publicSettings()]);
  if (!c) notFound();
  // QR manzili: sozlamadan; bo'sh bo'lsa — joriy sayt manzili.
  const h = await headers();
  const fallback = `${h.get("x-forwarded-proto") ?? "https"}://${h.get("host") ?? ""}/c`;
  const base = String(s?.["certificate.public_base_url"] || fallback);
  const url = `${base.replace(/\/$/, "")}/${encodeURIComponent(c.code)}`;
  const qr = await QRCode.toString(url, { type: "svg", margin: 0, errorCorrectionLevel: "M" });
  const dp = dateParts(c.issued_at.replace(" ", "T"));
  const date = `${dp.day}-${MONTHS[dp.month - 1]} ${dp.year}-yil`;
  const body = String(s?.["certificate.body"] ?? "{name}\n{course}")
    .replaceAll("{name}", c.name).replaceAll("{course}", c.course).replaceAll("{date}", date)
    .replaceAll("{months}", String(c.duration_months)).replaceAll("{hours}", String(c.duration_months));
  const bg = String(s?.["certificate.background_url"] ?? "");
  const logo = String(s?.["site.logo_url"] ?? "");
  const [nameLine, ...rest] = body.split("\n");

  // Bekor qilingan: sertifikat ko'rinishi emas — aniq "bekor qilingan" holati (ish beruvchi chalg'imasin).
  if (c.status === "REVOKED") {
    const rp = dateParts(String(c.revoked_at ?? c.issued_at).replace(" ", "T"));
    return (
      <div className="cert-wrap">
        <article className="cert-revoked" role="alert" aria-live="polite">
          <h1>{String(s?.["certificate.revoked_text"] || "❌ Sertifikat bekor qilingan")}</h1>
          <dl>
            <dt>№</dt><dd>{c.code}</dd>
            <dt>Egasi</dt><dd>{c.name}</dd>
            <dt>Kurs</dt><dd>{c.course}</dd>
            <dt>Berilgan</dt><dd>{date}</dd>
            <dt>Bekor qilingan</dt><dd>{`${rp.day}-${MONTHS[rp.month - 1]} ${rp.year}-yil`}</dd>
            {c.revoked_reason && <><dt>Sabab</dt><dd>{c.revoked_reason}</dd></>}
          </dl>
          <p>{String(s?.["site.name"] ?? "")}</p>
        </article>
      </div>
    );
  }

  return (
    <div className="cert-wrap">
      <div className="cert-actions"><span>{String(s?.["certificate.verified_text"] || "✅")}</span><PrintButton /></div>
      <article className="cert" style={bg ? { backgroundImage: `url(${bg})` } : undefined}>
        {logo && <img src={logo} alt="" className="cert-logo" />}
        <p className="cert-org">{String(s?.["site.name"] ?? "")}</p>
        <h1 className="cert-title">{String(s?.["certificate.title"] ?? "SERTIFIKAT")}</h1>
        <p className="cert-name">{nameLine}</p>
        {rest.map((l, i) => <p className="cert-text" key={i}>{l}</p>)}
        <footer className="cert-foot">
          <div>
            <p className="cert-sign">{String(s?.["certificate.signer_name"] ?? "")}</p>
            <p className="cert-muted">{String(s?.["certificate.signer_title"] ?? "")}</p>
          </div>
          <div className="cert-meta">
            <p>№ {c.code}</p>
            <p>{date}</p>
          </div>
          <div className="cert-qr" dangerouslySetInnerHTML={{ __html: qr }} />
        </footer>
      </article>
    </div>
  );
}
