import { redirect } from "next/navigation";
import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import Branches, { type Branch } from "./Branches";

export const metadata: Metadata = { title: "Filiallar" };

export default async function BranchesPage() {
  const user = await me();
  if (!user?.permissions.includes("user.manage")) redirect("/dashboard");
  const { data } = await api<{ data: Branch[] }>("/v1/branches");
  return <Branches rows={data.data ?? []} />;
}
