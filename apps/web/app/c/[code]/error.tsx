"use client";

export default function CertificateError({ error, reset }: { error: Error; reset: () => void }) {
  return (
    <main className="narrow" style={{ padding: "64px 16px", textAlign: "center" }}>
      <h1>Sertifikat</h1>
      <p className="muted">{error.message || "Sertifikatni hozir tekshirib bo'lmadi."}</p>
      <button className="btn" onClick={reset}>Qayta urinish</button>
    </main>
  );
}
