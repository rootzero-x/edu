import type { Metadata } from "next";
import Link from "next/link";
import { Award, ExternalLink } from "lucide-react";
import { api, me } from "@/lib/api";
import RevokeCertificate from "@/components/RevokeCertificate";
import { Badge, Card, EmptyState, PageHeader, Table, Td, Th } from "@/components/ui";

export const metadata: Metadata = { title: "Sertifikatlar" };

type Row = { code: string; issued_at: string; first_name: string; last_name: string | null; course: string; group: string;
  revoked_at?: string | null; revoked_reason?: string | null };

const day = (v: string) => String(v).slice(0, 10).split("-").reverse().join(".");

export default async function CertificatesPage({ searchParams }: { searchParams: Promise<{ revoked?: string }> }) {
  // issued_at API vaqt zonasida (app.timezone) mahalliy vaqt sifatida keladi.
  const showRevoked = (await searchParams).revoked === "1";
  const [{ data }, user] = await Promise.all([api<{ data: Row[] }>(`/v1/certificates${showRevoked ? "?revoked=1" : ""}`), me()]);
  const manage = Boolean(user?.permissions.includes("group.manage"));
  const staff = manage || Boolean(user?.permissions.includes("attendance.mark"));
  const rows = data.data ?? [];
  // Bo'sh holat matni rolga mos: kim beradi va bu yerda nima ko'rinadi.
  const empty = manage
    ? "Kursni tamomlab, shartlarni bajargan o'quvchilarga sertifikat guruh sahifasidan beriladi."
    : user?.permissions.includes("attendance.mark")
      ? "Siz o'qitayotgan guruhlar o'quvchilariga berilgan sertifikatlar shu yerda ko'rinadi. Sertifikatni administrator beradi."
      : user?.role === "PARENT"
        ? "Farzandingiz kursni tamomlab, shartlarni bajargach, sertifikati shu yerda paydo bo'ladi."
        : "Kursni tamomlab, shartlarni bajarganingizdan so'ng sertifikatingiz shu yerda paydo bo'ladi.";
  return (
    <>
      <PageHeader title="Sertifikatlar" description={`${rows.length} ta`}
        actions={staff && <Link className="a" href={showRevoked ? "/dashboard/certificates" : "/dashboard/certificates?revoked=1"}>
          {showRevoked ? "Faqat amaldagilar" : "Bekor qilinganlar bilan"}
        </Link>} />
      <Card>
        {rows.length === 0 ? (
          <EmptyState icon={<Award />} title="Sertifikatlar yo'q">{empty}</EmptyState>
        ) : (
          <Table stack>
            <thead><tr><Th>Raqam</Th><Th>O&apos;quvchi</Th><Th>Kurs</Th><Th>Guruh</Th><Th>Sana</Th>{manage && <Th actions><span className="sr-only">Amallar</span></Th>}</tr></thead>
            <tbody>
              {rows.map((c) => (
                <tr key={c.code}>
                  <Td label="Raqam"><a className="a" href={`/c/${c.code}`} target="_blank" rel="noreferrer">{c.code} <ExternalLink size={14} aria-hidden /></a></Td>
                  <Td label="O'quvchi">{[c.first_name, c.last_name].filter(Boolean).join(" ")}</Td>
                  <Td label="Kurs">{c.course}</Td>
                  <Td label="Guruh">{c.group}</Td>
                  <Td label="Sana">
                    {day(c.issued_at)}
                    {c.revoked_at && <div><Badge tone="danger">Bekor qilingan {day(c.revoked_at)}</Badge>{c.revoked_reason && <span className="subtle"> · {c.revoked_reason}</span>}</div>}
                  </Td>
                  {manage && <Td actions>{!c.revoked_at && <RevokeCertificate code={c.code} />}</Td>}
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}
