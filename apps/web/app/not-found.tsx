import { Compass, Home } from "lucide-react";
import { ButtonLink, Card, EmptyState } from "@/components/ui";

export default function NotFound() {
  return (
    <main style={{ maxWidth: 560, paddingTop: 80 }}>
      <Card>
        <EmptyState level={1} icon={<Compass />} title="Sahifa topilmadi" action={<ButtonLink href="/" icon={<Home />}>Bosh sahifa</ButtonLink>}>
          Havola eskirgan yoki sahifa o&apos;chirilgan bo&apos;lishi mumkin.
        </EmptyState>
      </Card>
    </main>
  );
}
