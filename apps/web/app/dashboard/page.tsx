import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import type { BillingMe } from "@/lib/billing";
import type { Summary } from "@/lib/types";
import BalanceCard from "@/components/billing/BalanceCard";
import SystemStatusCard, { type SystemStatus } from "@/components/SystemStatusCard";
import Overview from "./Overview";

export const metadata: Metadata = { title: "Bosh sahifa" };

export default async function DashboardHome() {
  const [user, res] = await Promise.all([me(), api<Summary>("/v1/dashboard/summary")]);
  // Balans — faqat o'quvchi (o'zi) va ota-ona (farzandlari) uchun.
  const billing = user && ["STUDENT", "PARENT"].includes(user.role) ? await api<BillingMe>("/v1/billing/me") : null;
  // Tizim holati (cron, zaxira, Telegram, arizalar) — foydalanuvchilarni boshqaradigan adminlar uchun.
  const system = user?.permissions.includes("user.manage") ? await api<SystemStatus>("/v1/system/status") : null;
  return (
    <Overview initial={res.status === 200 ? res.data : null} firstName={user?.first_name ?? ""}>
      {billing?.status === 200 && <BalanceCard data={billing.data} />}
      {system && <SystemStatusCard initial={system.status === 200 ? system.data : null} />}
    </Overview>
  );
}
