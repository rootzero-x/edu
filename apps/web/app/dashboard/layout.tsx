import { redirect } from "next/navigation";
import { api, publicSettings, type Me } from "@/lib/api";
import { siteName, str } from "@/lib/settings";
import Shell from "@/components/shell/Shell";
import ServerUnavailable from "@/components/ServerUnavailable";

export default async function DashboardLayout({ children }: { children: React.ReactNode }) {
  const [res, s] = await Promise.all([api<{ user: Me }>("/v1/auth/me"), publicSettings()]);
  if (res.status === 401 || res.status === 403) redirect("/login");
  // Faqat 401/403 — login; 429 va boshqa xatolarda sahifa o'zi qayta urinadi (sessiya saqlanadi).
  if (res.status !== 200) return <ServerUnavailable status={res.status} siteName={siteName(s)} />;
  const user = res.data.user;
  return (
    <Shell user={user} siteName={siteName(s)} logoUrl={str(s, "site.logo_url") || undefined}>
      {children}
    </Shell>
  );
}
