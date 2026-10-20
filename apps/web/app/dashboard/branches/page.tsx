import { redirect } from "next/navigation";
import { api, me } from "@/lib/api";
import Branches from "./Branches";

export default async function BranchesPage() {
  const user = await me();
  if (!user?.permissions.includes("user.manage")) redirect("/dashboard");
  const { data } = await api<{ data: any[] }>("/v1/branches");
  return <Branches rows={data.data ?? []} />;
}
