import { Compass, LayoutDashboard } from "lucide-react";
import { ButtonLink, Card, EmptyState } from "@/components/ui";

/** Panel ichidagi "topilmadi" holati. notFound() o'rniga to'g'ridan-to'g'ri qaytariladi:
 *  loading.tsx bilan oqimli renderda notFound() skeletonda qotib qoladi. */
export default function NotFoundCard() {
  return (
    <Card>
      <EmptyState icon={<Compass />} title="Topilmadi" action={<ButtonLink href="/dashboard" icon={<LayoutDashboard />}>Bosh panel</ButtonLink>}>
        Bu ma&apos;lumot mavjud emas yoki sizda uni ko&apos;rish huquqi yo&apos;q.
      </EmptyState>
    </Card>
  );
}
