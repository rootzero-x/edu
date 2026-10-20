const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:3100";

async function apiStatus(): Promise<"ok" | "down"> {
  try {
    const res = await fetch(`${API_URL}/health`, { cache: "no-store", signal: AbortSignal.timeout(3000) });
    return res.ok ? "ok" : "down";
  } catch {
    return "down";
  }
}

export const dynamic = "force-dynamic";

const FEATURES = [
  { title: "Kurslar va darslar", text: "Video, fayl va matnli darslar, modullar bo'yicha." },
  { title: "Testlar", text: "Vaqt cheklovi, urinishlar soni, avtomatik baholash." },
  { title: "Guruhlar va davomat", text: "Jadval, davomat, ota-onaga xabar." },
  { title: "To'lovlar", text: "Oylik to'lov, chegirma, qarzdorlik hisobi." },
  { title: "Sertifikat", text: "Kursni tugatganlarga QR-kodli sertifikat." },
  { title: "Telegram", text: "Bot va Mini App orqali kirish." },
];

export default async function Home() {
  const status = await apiStatus();
  return (
    <main>
      <h1>TISU Edu</h1>
      <p className="lead">O&apos;quv markazi platformasi. Tez orada ishga tushadi.</p>
      <div className="grid">
        {FEATURES.map((f) => (
          <div className="card" key={f.title}>
            <h2>{f.title}</h2>
            <p>{f.text}</p>
          </div>
        ))}
      </div>
      <p className="status">
        <span className="dot" style={{ background: status === "ok" ? "#16a34a" : "#dc2626" }} />
        API: {status === "ok" ? "ishlayapti" : "ulanmagan"}
      </p>
    </main>
  );
}
