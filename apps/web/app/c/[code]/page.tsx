import type { Metadata } from "next";
import { notFound } from "next/navigation";
import QRCode from "qrcode";
import { API_URL, publicSettings } from "@/lib/api";
import PrintButton from "./PrintButton";
import "./certificate.css";

type Cert = { code: string; issued_at: string; name: string; course: string; duration_months: number };

async function load(code: string): Promise<Cert | null> {
  const res = await fetch(`${API_URL}/v1/public/certificates/${encodeURIComponent(code)}`, { next: { revalidate: 300 } });
  return res.ok ? ((await res.json()) as { certificate: Cert }).certificate : null;
}

export async function generateMetadata({ params }: { params: Promise<{ code: string }> }): Promise<Metadata> {
  const { code } = await params;
  const c = await load(code);
  return { title: c ? `${c.name} — ${c.course}` : "Sertifikat topilmadi", robots: { index: false } };
}

export default async function CertificatePage({ params }: { params: Promise<{ code: string }> }) {
  const { code } = await params;
  const [c, s] = await Promise.all([load(code), publicSettings()]);
  if (!c) notFound();
  const base = String(s?.["certificate.public_base_url"] ?? "");
  const url = `${base.replace(/\/$/, "")}/${c.code}`;
  const qr = await QRCode.toString(url, { type: "svg", margin: 0, errorCorrectionLevel: "M" });
  const date = new Date(c.issued_at.replace(" ", "T")).toLocaleDateString("uz-UZ", { day: "2-digit", month: "long", year: "numeric" });
  const body = String(s?.["certificate.body"] ?? "{name}\n{course}")
    .replaceAll("{name}", c.name).replaceAll("{course}", c.course).replaceAll("{date}", date)
    .replaceAll("{hours}", String(c.duration_months));
  const bg = String(s?.["certificate.background_url"] ?? "");
  const logo = String(s?.["site.logo_url"] ?? "");
  const [nameLine, ...rest] = body.split("\n");

  return (
    <div className="cert-wrap">
      <div className="cert-actions"><span>✅ Sertifikat haqiqiy</span><PrintButton /></div>
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
