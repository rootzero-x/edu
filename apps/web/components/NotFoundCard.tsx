import { Compass, LayoutDashboard } from "lucide-react";
import { ButtonLink, Card, EmptyState } from "@/components/ui";

/** Panel ichidagi "topilmadi" holati. notFound() o'rniga to'g'ridan-to'g'ri qaytariladi:
 *  loading.tsx bilan oqimli renderda notFound() skeletonda qotib qoladi. */
export default function NotFoundCard({ message }: { message?: string | null }) {
  return (
    <Card>
      <EmptyState icon={<Compass />} title="Topilmadi" action={<ButtonLink href="/dashboard" icon={<LayoutDashboard />}>Bosh panel</ButtonLink>}>
        {/* API aniq sababni aytsa (masalan, "Dars topilmadi — o'chirilgan bo'lishi mumkin"), shuni ko'rsatamiz. */}
        {message || "Bu ma'lumot mavjud emas yoki sizda uni ko'rish huquqi yo'q."}
      </EmptyState>
    </Card>
  );
}
