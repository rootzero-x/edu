import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { api, publicSettings } from "@/lib/api";
import { str } from "@/lib/settings";
import type { BillingMe } from "@/lib/billing";
import BalanceCard from "@/components/billing/BalanceCard";
import Profile, { type ProfileUser } from "./Profile";

export const metadata: Metadata = { title: "Profil" };

export default async function ProfilePage() {
  const [r, st] = await Promise.all([api<{ user: ProfileUser }>("/v1/auth/me"), publicSettings()]);
  if (r.status !== 200) redirect("/login");
  const bot = str(st, "telegram.bot_username").replace(/^@/, "");
  const billing = ["STUDENT", "PARENT"].includes(r.data.user.role) ? await api<BillingMe>("/v1/billing/me") : null;
  return (
    <Profile user={r.data.user} botUrl={bot ? `https://t.me/${bot}?start=link` : null}>
      {billing?.status === 200 && <BalanceCard data={billing.data} />}
    </Profile>
  );
}
