"use client";

import { LogOut } from "lucide-react";
import { Button } from "@/components/ui";

export async function logout() {
  await fetch("/api/auth/logout", { method: "POST" }).catch(() => null);
  window.location.href = "/login";
}

export default function LogoutButton() {
  return <Button variant="ghost" size="sm" icon={<LogOut />} onClick={logout}>Chiqish</Button>;
}
