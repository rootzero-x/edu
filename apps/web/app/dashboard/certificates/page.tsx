import { api } from "@/lib/api";

export default async function CertificatesPage() {
  // issued_at API vaqt zonasida (app.timezone) mahalliy vaqt sifatida keladi.
  const { data } = await api<{ data: any[] }>("/v1/certificates");
  const rows = data.data ?? [];
  return (
    <>
      <h1>Sertifikatlar</h1>
      <table>
        <thead><tr><th>Raqam</th><th>O&apos;quvchi</th><th>Kurs</th><th>Guruh</th><th>Sana</th></tr></thead>
        <tbody>
          {rows.map((c) => (
            <tr key={c.code}>
              <td><a className="a" href={`/c/${c.code}`} target="_blank" rel="noreferrer">{c.code}</a></td>
              <td>{c.first_name} {c.last_name}</td><td>{c.course}</td><td>{c.group}</td><td>{String(c.issued_at).slice(0, 16)}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {rows.length === 0 && <p className="muted">Sertifikatlar yo&apos;q.</p>}
    </>
  );
}
