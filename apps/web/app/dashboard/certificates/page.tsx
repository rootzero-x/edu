import type { Metadata } from "next";
import { Award, ExternalLink } from "lucide-react";
import { api } from "@/lib/api";
import { Card, EmptyState, PageHeader, Table, Td, Th } from "@/components/ui";

export const metadata: Metadata = { title: "Sertifikatlar" };

type Row = { code: string; issued_at: string; first_name: string; last_name: string | null; course: string; group: string };

export default async function CertificatesPage() {
  // issued_at API vaqt zonasida (app.timezone) mahalliy vaqt sifatida keladi.
  const { data } = await api<{ data: Row[] }>("/v1/certificates");
  const rows = data.data ?? [];
  return (
    <>
      <PageHeader title="Sertifikatlar" description={`${rows.length} ta`} />
      <Card>
        {rows.length === 0 ? (
          <EmptyState icon={<Award />} title="Sertifikatlar yo'q">Kursni tamomlab, shartlarni bajargan o&apos;quvchilarga sertifikat guruh sahifasidan beriladi.</EmptyState>
        ) : (
          <Table>
            <thead><tr><Th>Raqam</Th><Th>O&apos;quvchi</Th><Th>Kurs</Th><Th>Guruh</Th><Th>Sana</Th></tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.code}>
                  <Td><a className="a" href={`/c/${c.code}`} target="_blank" rel="noreferrer">{c.code} <ExternalLink size={14} aria-hidden /></a></Td>
                  <Td>{[c.first_name, c.last_name].filter(Boolean).join(" ")}</Td>
                  <Td>{c.course}</Td>
                  <Td>{c.group}</Td>
                  <Td>{String(c.issued_at).slice(0, 10).split("-").reverse().join(".")}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}
