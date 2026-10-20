import { Compass, LayoutDashboard } from "lucide-react";
import { ButtonLink, Card, EmptyState } from "@/components/ui";

export default function DashboardNotFound() {
  return (
    <Card>
      <EmptyState icon={<Compass />} title="Topilmadi" action={<ButtonLink href="/dashboard" icon={<LayoutDashboard />}>Bosh panel</ButtonLink>}>
        Bu ma&apos;lumot mavjud emas yoki sizda uni ko&apos;rish huquqi yo&apos;q.
      </EmptyState>
    </Card>
  );
}
