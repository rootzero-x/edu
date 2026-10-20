"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useState, type ReactNode } from "react";
import { ChevronsUpDown, LogOut, Menu, UserRound } from "lucide-react";
import { Avatar, Button, Dropdown, cx } from "@/components/ui";
import ThemeToggle from "@/components/ThemeToggle";
import { logout } from "@/components/LogoutButton";
import { NAV, ROLE_LABELS } from "./nav";
import s from "./Shell.module.css";

type User = { first_name: string; last_name: string | null; role: string; phone: string; permissions: string[] };

export default function Shell({ user, siteName, logoUrl, children }: { user: User; siteName: string; logoUrl?: string; children: ReactNode }) {
  const path = usePathname();
  const [open, setOpen] = useState(false);
  useEffect(() => setOpen(false), [path]);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  const items = NAV.filter((n) => !n.perm || user.permissions.includes(n.perm));
  const isActive = (href: string, exact?: boolean) => (exact ? path === href : path === href || path.startsWith(`${href}/`));
  const current = items.filter((n) => isActive(n.href, n.exact)).sort((a, b) => b.href.length - a.href.length)[0];
  const name = [user.first_name, user.last_name].filter(Boolean).join(" ");

  const link = (n: (typeof items)[number]) => {
    const Icon = n.icon;
    return (
      <Link key={n.href} href={n.href} className={s.link} aria-current={isActive(n.href, n.exact) ? "page" : undefined}>
        <Icon aria-hidden />{n.label}
      </Link>
    );
  };
  const main = items.filter((n) => n.section !== "admin");
  const admin = items.filter((n) => n.section === "admin");

  return (
    <div className={s.shell}>
      <a href="#content" className="skip-link">Asosiy qismga o&apos;tish</a>
      <aside className={cx(s.side, open && s.open)} aria-label="Asosiy menyu">
        <Link href="/dashboard" className={s.brand}>
          <span className={s.brandMark} aria-hidden>{logoUrl ? <img src={logoUrl} alt="" /> : siteName.slice(0, 1)}</span>
          <span className={s.brandName}>{siteName}</span>
        </Link>
        <nav className={s.nav}>
          {main.map(link)}
          {admin.length > 0 && <div className={s.navLabel}>Boshqaruv</div>}
          {admin.map(link)}
        </nav>
        <div className={s.sideFoot}>
          <Dropdown
            align="left"
            direction="up"
            trigger={(p) => (
              <button type="button" className={s.userBtn} {...p}>
                <Avatar name={name} size={34} />
                <span className={s.userText}>
                  <span className={s.userName}>{name}</span>
                  <span className={s.userRole}>{ROLE_LABELS[user.role] ?? user.role}</span>
                </span>
                <ChevronsUpDown aria-hidden />
              </button>
            )}
            items={[
              { type: "label", label: user.phone },
              { type: "separator" },
              { label: "Chiqish", icon: <LogOut />, danger: true, onSelect: logout },
            ]}
          />
        </div>
      </aside>
      {open && <div className={s.scrim} onClick={() => setOpen(false)} aria-hidden />}
      <div className={s.main}>
        <header className={s.topbar}>
          <Button variant="ghost" iconOnly icon={<Menu />} aria-label="Menyuni ochish" aria-expanded={open} className={s.menuBtn} onClick={() => setOpen(true)} />
          <div className={s.crumb}>{current?.label ?? siteName}</div>
          <ThemeToggle />
          <Dropdown
            trigger={(p) => <Button variant="ghost" iconOnly aria-label="Profil" icon={<UserRound />} {...p} />}
            items={[
              { type: "label", label: <>{name}<br />{ROLE_LABELS[user.role] ?? user.role}</> },
              { type: "separator" },
              { label: "Chiqish", icon: <LogOut />, danger: true, onSelect: logout },
            ]}
          />
        </header>
        <main id="content" className={s.content} tabIndex={-1}>{children}</main>
      </div>
    </div>
  );
}
