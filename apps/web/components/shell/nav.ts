import { Award, BarChart3, Bell, CalendarDays, Wallet, Megaphone, BookOpen, CircleUserRound, ClipboardCheck, Inbox, LayoutDashboard, NotebookPen, ScrollText, Settings, Users, UsersRound, type LucideIcon } from "lucide-react";

export type NavItem = {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Ko'rinishi uchun kerakli huquq (permissions ro'yxatidan). */
  perm?: string;
  /** Faqat shu rollarga (ko'rsatilmasa — huquqqa qarab hammaga). */
  roles?: string[];
  exact?: boolean;
  section?: "main" | "admin";
  /** Ochiq sozlama (bool) o'chirilgan bo'lsa bo'lim ko'rinmaydi (masalan, homework.enabled). */
  setting?: string;
  /** Shu bo'limga tegishli boshqa yo'llar (masalan, dars sahifasi "Kurslar" ostida). */
  also?: string[];
};

/**
 * Panel menyusi. Yangi bo'lim qo'shish uchun shu ro'yxatga bitta qator yetarli
 * (masalan, testlar: { href: "/dashboard/quizzes", label: "Testlar", icon: ClipboardCheck, perm: "course.view" }).
 */
export const NAV: NavItem[] = [
  { href: "/dashboard", label: "Bosh sahifa", icon: LayoutDashboard, exact: true },
  { href: "/dashboard/courses", label: "Kurslar", icon: BookOpen, perm: "course.view", also: ["/dashboard/lessons", "/dashboard/quizzes"] },
  { href: "/dashboard/groups", label: "Guruhlar", icon: UsersRound, also: ["/dashboard/sessions"] },
  { href: "/dashboard/leads", label: "Arizalar", icon: Inbox, perm: "lead.manage" },
  { href: "/dashboard/calendar", label: "Taqvim", icon: CalendarDays },
  { href: "/dashboard/homework", label: "Uyga vazifa", icon: NotebookPen, setting: "homework.enabled" },
  { href: "/dashboard/news", label: "Yangiliklar", icon: Megaphone },
  { href: "/dashboard/certificates", label: "Sertifikatlar", icon: Award },
  { href: "/dashboard/reports", label: "Hisobotlar", icon: BarChart3, perm: "report.view" },
  { href: "/dashboard/billing", label: "To'lovlar", icon: Wallet, perm: "billing.manage" },
  { href: "/dashboard/profile", label: "Profil", icon: CircleUserRound },
  { href: "/dashboard/users", label: "Foydalanuvchilar", icon: Users, perm: "user.manage", section: "admin" },
  { href: "/dashboard/notifications", label: "Bildirishnomalar", icon: Bell, perm: "user.manage", section: "admin" },
  { href: "/dashboard/audit", label: "Audit jurnali", icon: ScrollText, perm: "audit.view", section: "admin" },
  { href: "/dashboard/settings", label: "Sozlamalar", icon: Settings, perm: "settings.manage", section: "admin" },
];

// Boshqa bo'limlar ishlatishi uchun eksport qilingan ikonkalar.
export const NAV_ICONS = { Award, ClipboardCheck };

export const ROLE_LABELS: Record<string, string> = {
  SUPER_ADMIN: "Super-admin", ADMIN: "Admin", TEACHER: "O'qituvchi", STUDENT: "O'quvchi",
};
