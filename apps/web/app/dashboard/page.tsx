import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import type { Summary } from "@/lib/types";
import Overview from "./Overview";

export const metadata: Metadata = { title: "Bosh sahifa" };

export default async function DashboardHome() {
  const [user, res] = await Promise.all([me(), api<Summary>("/v1/dashboard/summary")]);
  return <Overview initial={res.status === 200 ? res.data : null} firstName={user?.first_name ?? ""} />;
}
