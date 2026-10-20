import { Suspense } from "react";
import Link from "next/link";
import { redirect } from "next/navigation";
import { cookies } from "next/headers";
import type { Metadata } from "next";
import { safeNext } from "@/lib/safe";
import { ArrowLeft, CheckCircle2 } from "lucide-react";
import { TOKEN_COOKIE, me, publicSettings } from "@/lib/api";
import { list, siteName, str } from "@/lib/settings";
import ThemeToggle from "@/components/ThemeToggle";
import { SafeImage } from "@/components/ui";
import LoginForm from "./LoginForm";
import s from "./login.module.css";

export async function generateMetadata(): Promise<Metadata> {
  const st = await publicSettings();
  return { title: str(st, "login.title", "Kirish") };
}

export default async function LoginPage({ searchParams }: { searchParams: Promise<{ next?: string }> }) {
  // Allaqachon kirgan bo'lsa — kabinetga (token yaroqli bo'lsagina, aks holda sikl bo'lmasin).
  if ((await cookies()).get(TOKEN_COOKIE) && (await me())) {
    const n = (await searchParams).next;
    redirect(safeNext(n));
  }
  const st = await publicSettings();
  const name = siteName(st);
  const logo = str(st, "site.logo_url");
  const points = list<string>(st, "login.side_points").filter((p) => typeof p === "string" && p);
  const telegramEnabled = Boolean(str(st, "telegram.bot_username"));
  const lead = telegramEnabled ? str(st, "login.subtitle") : str(st, "login.subtitle_password");
  const mark = <span className={s.mark} aria-hidden><SafeImage src={logo} fallback={name.slice(0, 1)} /></span>;

  return (
    <div className={s.wrap}>
      <aside className={s.aside}>
        <Link href="/" className={s.brand}>{mark}{name}</Link>
        <div className={s.asideText}>
          <h2>{str(st, "login.side_title", str(st, "site.tagline"))}</h2>
          {points.length > 0 && (
            <ul className={s.points}>
              {points.map((p) => <li key={p}><CheckCircle2 aria-hidden />{p}</li>)}
            </ul>
          )}
        </div>
        <div className={s.asideFoot}>{str(st, "site.tagline")}</div>
      </aside>
      <div className={s.panel}>
        <div className={s.panelTop}>
          <Link href="/" className={s.back} aria-label={name}><ArrowLeft aria-hidden /><span className={s.mobileBrand}>{mark}{name}</span></Link>
          <ThemeToggle />
        </div>
        <div className={s.center}>
          <div className={s.box}>
            <h1>{str(st, "login.title", name)}</h1>
            {lead && <p className={s.lead}>{lead}</p>}
            <Suspense>
              <LoginForm telegramEnabled={telegramEnabled} />
            </Suspense>
          </div>
        </div>
      </div>
    </div>
  );
}
