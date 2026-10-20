import type { Metadata } from "next";
import { Award, ExternalLink } from "lucide-react";
import { api, me } from "@/lib/api";
import RevokeCertificate from "@/components/RevokeCertificate";
import { Card, EmptyState, PageHeader, Table, Td, Th } from "@/components/ui";

export const metadata: Metadata = { title: "Sertifikatlar" };

type Row = { code: string; issued_at: string; first_name: string; last_name: string | null; course: string; group: string };

export default async function CertificatesPage() {
  // issued_at API vaqt zonasida (app.timezone) mahalliy vaqt sifatida keladi.
  const [{ data }, user] = await Promise.all([api<{ data: Row[] }>("/v1/certificates"), me()]);
  const manage = Boolean(user?.permissions.includes("group.manage"));
  const rows = data.data ?? [];
  return (
    <>
      <PageHeader title="Sertifikatlar" description={`${rows.length} ta`} />
      <Card>
        {rows.length === 0 ? (
          <EmptyState icon={<Award />} title="Sertifikatlar yo'q">Kursni tamomlab, shartlarni bajargan o&apos;quvchilarga sertifikat guruh sahifasidan beriladi.</EmptyState>
        ) : (
          <Table stack>
            <thead><tr><Th>Raqam</Th><Th>O&apos;quvchi</Th><Th>Kurs</Th><Th>Guruh</Th><Th>Sana</Th>{manage && <Th />}</tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.code}>
                  <Td label="Raqam"><a className="a" href={`/c/${c.code}`} target="_blank" rel="noreferrer">{c.code} <ExternalLink size={14} aria-hidden /></a></Td>
                  <Td label="O'quvchi">{[c.first_name, c.last_name].filter(Boolean).join(" ")}</Td>
                  <Td label="Kurs">{c.course}</Td>
                  <Td label="Guruh">{c.group}</Td>
                  <Td label="Sana">{String(c.issued_at).slice(0, 10).split("-").reverse().join(".")}</Td>
                  {manage && <Td><RevokeCertificate code={c.code} /></Td>}
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}
