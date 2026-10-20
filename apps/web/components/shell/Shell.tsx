"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { ChevronsUpDown, LogOut, Menu, UserRound } from "lucide-react";
import { Avatar, Button, Dropdown, SafeImage, cx } from "@/components/ui";
import ThemeToggle from "@/components/ThemeToggle";
import { logout } from "@/components/LogoutButton";
import { NAV, ROLE_LABELS } from "./nav";
import { useSiteSettings } from "@/components/SiteProvider";
import { obj } from "@/lib/settings";
import { call } from "@/lib/client";
import CommandPalette from "./CommandPalette";
import s from "./Shell.module.css";

type User = { first_name: string; last_name: string | null; role: string; phone: string; permissions: string[] };

export default function Shell({ user, siteName, logoUrl, children }: { user: User; siteName: string; logoUrl?: string; children: ReactNode }) {
  const path = usePathname();
  const [open, setOpen] = useState(false);
  const [mobile, setMobile] = useState(false);
  const sideRef = useRef<HTMLElement>(null);
  const st = useSiteSettings();
  const labels = obj<Record<string, string>>(st, "ui.nav_labels");
  const roles = { ...ROLE_LABELS, ...obj<Record<string, string>>(st, "telegram.role_labels") };
  useEffect(() => setOpen(false), [path]);
  // O'qilmagan yangiliklar soni (menyuda belgi): sahifa almashganda va har daqiqada.
  const [unread, setUnread] = useState(0);
  useEffect(() => {
    let alive = true;
    const load = () => call<{ unread: number }>("GET", "announcements/unread").then((r) => alive && r.ok && setUnread(r.data.unread)).catch(() => {});
    load();
    const id = setInterval(() => document.visibilityState === "visible" && load(), 60000);
    return () => { alive = false; clearInterval(id); };
  }, [path]);
  // Mobil: yopiq tortma klaviatura/ekran o'quvchi uchun ham yopiq (inert); ochilganda fokus ichkariga.
  useEffect(() => {
    const mq = window.matchMedia("(max-width: 900px)");
    const sync = () => setMobile(mq.matches);
    sync();
    mq.addEventListener("change", sync);
    return () => mq.removeEventListener("change", sync);
  }, []);
  useEffect(() => {
    if (open) sideRef.current?.querySelector<HTMLElement>("a, button")?.focus();
  }, [open]);
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => e.key === "Escape" && setOpen(false);
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);

  const items = NAV.filter((n) => (!n.perm || user.permissions.includes(n.perm)) && (!n.roles || n.roles.includes(user.role)));
  const isActive = (href: string, exact?: boolean) => (exact ? path === href : path === href || path.startsWith(`${href}/`));
  const inSection = (n: (typeof items)[number]) => isActive(n.href, n.exact) || Boolean(n.also?.some((h) => isActive(h)));
  const current = items.filter(inSection).sort((a, b) => b.href.length - a.href.length)[0];
  const name = [user.first_name, user.last_name].filter(Boolean).join(" ");

  const link = (n: (typeof items)[number]) => {
    const Icon = n.icon;
    return (
      <Link key={n.href} href={n.href} className={s.link} aria-current={inSection(n) ? "page" : undefined}
        aria-label={n.href === "/dashboard/news" && unread > 0 ? `${labels[n.href] || n.label}, ${unread} ta o'qilmagan` : undefined}>
        <Icon aria-hidden />{labels[n.href] || n.label}
        {/* Nishon ko'rinadigan raqam; ekran o'quvchi uchun nom havolaning o'zida (span'dagi aria-label o'qilmaydi). */}
        {n.href === "/dashboard/news" && unread > 0 && <span className={s.count} aria-hidden>{unread > 99 ? "99+" : unread}</span>}
      </Link>
    );
  };
  const main = items.filter((n) => n.section !== "admin");
  const admin = items.filter((n) => n.section === "admin");

  return (
    <div className={s.shell}>
      <a href="#content" className="skip-link">Asosiy qismga o&apos;tish</a>
      <aside
        ref={sideRef}
        className={cx(s.side, open && s.open)}
        aria-label="Asosiy menyu"
        inert={mobile && !open ? true : undefined}
        {...(mobile && open ? { role: "dialog", "aria-modal": true } : {})}
      >
        <Link href="/dashboard" className={s.brand}>
          <span className={s.brandMark} aria-hidden><SafeImage src={logoUrl} fallback={siteName.slice(0, 1)} /></span>
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
                  <span className={s.userRole}>{roles[user.role] ?? user.role}</span>
                </span>
                <ChevronsUpDown aria-hidden />
              </button>
            )}
            items={[
              { type: "label", label: user.phone },
              { label: labels["/dashboard/profile"] || "Profil", icon: <UserRound />, onSelect: () => { window.location.href = "/dashboard/profile"; } },
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
          <div className={s.crumb}>{current ? labels[current.href] || current.label : siteName}</div>
          <CommandPalette nav={items.map((n) => ({ href: n.href, label: labels[n.href] || n.label }))} />
          <ThemeToggle />
          <Dropdown
            trigger={(p) => <Button variant="ghost" iconOnly aria-label="Profil" icon={<UserRound />} {...p} />}
            items={[
              { type: "label", label: <>{name}<br />{roles[user.role] ?? user.role}</> },
              { label: labels["/dashboard/profile"] || "Profil", icon: <UserRound />, onSelect: () => { window.location.href = "/dashboard/profile"; } },
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
