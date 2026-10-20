import { redirect } from "next/navigation";
import { api, publicSettings, type Me } from "@/lib/api";
import { siteName, str } from "@/lib/settings";
import Shell from "@/components/shell/Shell";

export default async function DashboardLayout({ children }: { children: React.ReactNode }) {
  const [res, s] = await Promise.all([api<{ user: Me }>("/v1/auth/me"), publicSettings()]);
  if (res.status === 401 || res.status === 403) redirect("/login");
  if (res.status !== 200) {
    return (
      <main className="narrow" style={{ padding: "64px 16px", textAlign: "center" }}>
        <h1>{siteName(s)}</h1>
        <p className="muted">Server vaqtincha javob bermayapti. Bir necha daqiqadan so&apos;ng qayta urinib ko&apos;ring.</p>
        <p><a className="a" href="">Qayta yuklash</a></p>
      </main>
    );
  }
  const user = res.data.user;
  return (
    <Shell user={user} siteName={siteName(s)} logoUrl={str(s, "site.logo_url") || undefined}>
      {children}
    </Shell>
  );
}
