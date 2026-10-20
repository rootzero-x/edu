import Link from "next/link";
import { redirect } from "next/navigation";
import { me, publicSettings } from "@/lib/api";
import LogoutButton from "@/components/LogoutButton";

const ROLE: Record<string, string> = { SUPER_ADMIN: "Super-admin", ADMIN: "Admin", TEACHER: "O'qituvchi", STUDENT: "O'quvchi", PARENT: "Ota-ona" };

export default async function DashboardLayout({ children }: { children: React.ReactNode }) {
  const [user, s] = await Promise.all([me(), publicSettings()]);
  if (!user) redirect("/login");
  const can = (p: string) => user.permissions.includes(p);
  return (
    <div className="shell">
      <nav className="side">
        <Link href="/dashboard" className="brand">{s?.["site.name"] ?? "TISU Edu"}</Link>
        <Link href="/dashboard">Kurslar</Link>
        <Link href="/dashboard/groups">Guruhlar</Link>
        <Link href="/dashboard/certificates">Sertifikatlar</Link>
        {can("user.manage") && <Link href="/dashboard/users">Foydalanuvchilar</Link>}
        {can("user.manage") && <Link href="/dashboard/branches">Filiallar</Link>}
        {can("settings.manage") && <Link href="/dashboard/settings">Sozlamalar</Link>}
        <div className="who">
          {user.first_name} · {ROLE[user.role] ?? user.role}
          <LogoutButton />
        </div>
      </nav>
      <section className="content">{children}</section>
    </div>
  );
}
