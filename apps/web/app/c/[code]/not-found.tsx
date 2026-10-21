import { SearchX } from "lucide-react";
import { ButtonLink, Card, EmptyState } from "@/components/ui";

/** Sertifikat topilmadi: raqam noto'g'ri yoki sertifikat mavjud emas. */
export default function CertificateNotFound() {
  return (
    <main style={{ maxWidth: 560, margin: "0 auto", padding: "80px 16px" }}>
      <Card>
        <EmptyState level={1} icon={<SearchX />} title="Sertifikat topilmadi" action={<ButtonLink href="/">Bosh sahifa</ButtonLink>}>
          Sertifikat raqamini tekshiring. Raqam sertifikatning pastki qismida va QR-kodda ko&apos;rsatilgan.
        </EmptyState>
      </Card>
    </main>
  );
}
