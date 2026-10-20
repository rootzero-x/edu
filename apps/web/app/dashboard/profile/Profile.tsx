"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { KeyRound, Send, UserRound } from "lucide-react";
import { Avatar, Badge, Button, Card, CardBody, CardHeader, Field, Input, PageHeader, buttonClass, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { obj } from "@/lib/settings";

export type ProfileUser = {
  id: number; phone: string; first_name: string; last_name: string | null; role: string;
  telegram: { linked: boolean; username?: string | null }; has_password: boolean;
};

export default function Profile({ user, botUrl }: { user: ProfileUser; botUrl: string | null }) {
  const router = useRouter();
  const toast = useToast();
  const roles = obj<Record<string, string>>(useSiteSettings(), "telegram.role_labels");
  const [name, setName] = useState({ first_name: user.first_name, last_name: user.last_name ?? "" });
  const [pw, setPw] = useState({ current_password: "", new_password: "", confirm: "" });
  const [busy, setBusy] = useState<"name" | "pw" | null>(null);

  async function saveName(e: React.FormEvent) {
    e.preventDefault();
    setBusy("name");
    const r = await call("PATCH", "auth/me", { first_name: name.first_name.trim(), last_name: name.last_name.trim() || null });
    setBusy(null);
    if (!r.ok) return toast.error("Saqlanmadi", errorText(r.data));
    toast.success("Profil yangilandi");
    router.refresh();
  }

  async function savePassword(e: React.FormEvent) {
    e.preventDefault();
    if (pw.new_password !== pw.confirm) return toast.error("Parollar mos emas");
    setBusy("pw");
    const r = await call("POST", "auth/password", { current_password: user.has_password ? pw.current_password : null, new_password: pw.new_password });
    setBusy(null);
    if (!r.ok) return toast.error("Parol o'zgartirilmadi", errorText(r.data));
    setPw({ current_password: "", new_password: "", confirm: "" });
    toast.success(user.has_password ? "Parol o'zgartirildi" : "Parol o'rnatildi", "Boshqa qurilmalardagi sessiyalar yopildi.");
    router.refresh();
  }

  const full = [user.first_name, user.last_name].filter(Boolean).join(" ");
  return (
    <>
      <PageHeader title="Profil" description={`${full} · ${roles[user.role] ?? user.role}`} />
      <div className="grid2">
        <Card>
          <CardHeader title="Shaxsiy ma'lumotlar" />
          <CardBody>
            <form onSubmit={saveName} style={{ display: "grid", gap: 12 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 12 }}><Avatar name={full} size={48} /><div><b>{full}</b><div className="subtle">{user.phone}</div></div></div>
              <Field label="Ism" required><Input value={name.first_name} onChange={(e) => setName({ ...name, first_name: e.target.value })} required maxLength={64} /></Field>
              <Field label="Familiya"><Input value={name.last_name} onChange={(e) => setName({ ...name, last_name: e.target.value })} maxLength={64} /></Field>
              <Field label="Telefon" hint="Telefonni administrator o'zgartiradi"><Input value={user.phone} disabled /></Field>
              <div><Button type="submit" icon={<UserRound />} loading={busy === "name"}>Saqlash</Button></div>
            </form>
          </CardBody>
        </Card>

        <div style={{ display: "grid", gap: 16, alignContent: "start" }}>
          <Card>
            <CardHeader title="Telegram" actions={user.telegram.linked ? <Badge tone="success" dot>Bog&apos;langan</Badge> : <Badge tone="warning" dot>Bog&apos;lanmagan</Badge>} />
            <CardBody>
              {user.telegram.linked ? (
                <p className="muted" style={{ margin: 0 }}>Telegram akkauntingiz bog&apos;langan{user.telegram.username ? ` (@${user.telegram.username})` : ""}. Dars eslatmalari va xabarlar botga keladi, saytga Telegram orqali kirishingiz mumkin.</p>
              ) : (
                <>
                  <p className="muted" style={{ marginTop: 0 }}>Botni oching va telefon raqamingizni yuboring — akkauntingiz avtomatik bog&apos;lanadi.</p>
                  {botUrl && <a className={buttonClass({})} href={botUrl} target="_blank" rel="noreferrer"><Send aria-hidden /> Botni ochish</a>}
                </>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader title={user.has_password ? "Parolni o'zgartirish" : "Parol o'rnatish"} description={user.has_password ? undefined : "Telegram'siz telefon va parol bilan kirish uchun"} />
            <CardBody>
              <form onSubmit={savePassword} style={{ display: "grid", gap: 12 }}>
                {user.has_password && <Field label="Joriy parol" required><Input type="password" autoComplete="current-password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} required /></Field>}
                <Field label="Yangi parol" required><Input type="password" autoComplete="new-password" value={pw.new_password} onChange={(e) => setPw({ ...pw, new_password: e.target.value })} required /></Field>
                <Field label="Yangi parolni takrorlang" required><Input type="password" autoComplete="new-password" value={pw.confirm} onChange={(e) => setPw({ ...pw, confirm: e.target.value })} required /></Field>
                <div><Button type="submit" icon={<KeyRound />} loading={busy === "pw"}>{user.has_password ? "O'zgartirish" : "O'rnatish"}</Button></div>
              </form>
            </CardBody>
          </Card>
        </div>
      </div>
    </>
  );
}
