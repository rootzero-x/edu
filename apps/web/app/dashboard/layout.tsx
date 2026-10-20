import { redirect } from "next/navigation";
import { me, publicSettings } from "@/lib/api";
import { siteName, str } from "@/lib/settings";
import Shell from "@/components/shell/Shell";

export default async function DashboardLayout({ children }: { children: React.ReactNode }) {
  const [user, s] = await Promise.all([me(), publicSettings()]);
  if (!user) redirect("/login");
  return (
    <Shell user={user} siteName={siteName(s)} logoUrl={str(s, "site.logo_url") || undefined}>
      {children}
    </Shell>
  );
}
