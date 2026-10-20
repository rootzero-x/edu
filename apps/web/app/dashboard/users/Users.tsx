"use client";

import { useEffect, useState, useTransition } from "react";
import { usePathname, useRouter } from "next/navigation";
import { ChevronLeft, ChevronRight, KeyRound, MoreHorizontal, Pencil, Search, ShieldOff, ShieldCheck, UserPlus, Users as UsersIcon } from "lucide-react";
import { Avatar, Badge, Button, Card, Dropdown, EmptyState, Input, PageHeader, Table, Tabs, Td, Th, type Tone, usePrompt, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { obj } from "@/lib/settings";
import { ROLE_LABELS } from "@/components/shell/nav";
import { call, errorText } from "@/lib/client";
import { personName } from "@/lib/format";

export type UserRow = { id: number; phone: string; first_name: string; last_name: string | null; role: string; active: boolean };
const ROLES = ["STUDENT", "TEACHER", "ADMIN", "SUPER_ADMIN"];
const ROLE_TONE: Record<string, Tone> = { STUDENT: "info", TEACHER: "accent", ADMIN: "warning", SUPER_ADMIN: "danger" };

export default function Users({ rows: initialRows, meta, role, q, actorRole, selfId }: {
  rows: UserRow[]; meta: { total: number; page: number; pages: number }; role: string; q: string; actorRole: string; selfId: number;
}) {
  // Rol nomlari sozlamalardan (telegram.role_labels) — bot, Mini App va sayt bir xil ko'rsatadi.
  const roleNames = { ...ROLE_LABELS, ...obj<Record<string, string>>(useSiteSettings(), "telegram.role_labels") };
  const router = useRouter();
  const path = usePathname();
  const toast = useToast();
  const prompt = usePrompt();
  const [rows, setRows] = useState(initialRows);
  const [search, setSearch] = useState(q);
  const [loading, startTransition] = useTransition();
  useEffect(() => setRows(initialRows), [initialRows]);

  const roles = ROLES.filter((r) => r !== "SUPER_ADMIN" || actorRole === "SUPER_ADMIN");
  const go = (params: Record<string, string>) => {
    const sp = new URLSearchParams({ role, q, ...params });
    for (const [k, v] of [...sp.entries()]) if (!v) sp.delete(k);
    startTransition(() => router.replace(`${path}${sp.size ? `?${sp}` : ""}`));
  };

  // Qidiruv: yozish to'xtagach 350ms dan keyin.
  useEffect(() => {
    if (search === q) return;
    const t = setTimeout(() => go({ q: search, page: "" }), 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  const canEdit = (u: UserRow) => u.id !== selfId && (u.role !== "SUPER_ADMIN" || actorRole === "SUPER_ADMIN");

  const addUser = () => prompt({
    title: "Yangi foydalanuvchi",
    description: "O'quvchilar Telegram bot orqali o'zi ham ro'yxatdan o'tishi mumkin.",
    fields: [
      { name: "phone", label: "Telefon", type: "tel", defaultValue: "+998", required: true, placeholder: "+998901234567" },
      { name: "first_name", label: "Ism", required: true },
      { name: "last_name", label: "Familiya" },
      { name: "role", label: "Rol", type: "select", required: true, defaultValue: role || "STUDENT", options: roles.map((r) => ({ value: r, label: roleNames[r] ?? r })) },
      { name: "password", label: "Parol", type: "password", hint: "Ixtiyoriy — Telegram orqali kirish uchun shart emas", autoComplete: "new-password" },
    ],
    submitText: "Qo'shish",
    onSubmit: async (v) => {
      const r = await call("POST", "users", { ...v, last_name: v.last_name || null, password: v.password || undefined });
      if (!r.ok) return errorText(r.data);
      toast.success("Foydalanuvchi qo'shildi", `${v.first_name} · ${v.phone}`);
      router.refresh();
    },
  });

  const edit = (u: UserRow) => prompt({
    title: "Ma'lumotlarni tahrirlash",
    fields: [
      { name: "first_name", label: "Ism", required: true, defaultValue: u.first_name },
      { name: "last_name", label: "Familiya", defaultValue: u.last_name ?? "" },
      { name: "phone", label: "Telefon", type: "tel", required: true, defaultValue: u.phone },
      { name: "role", label: "Rol", type: "select", required: true, defaultValue: u.role, options: roles.map((r) => ({ value: r, label: roleNames[r] ?? r })) },
    ],
    onSubmit: async (v) => {
      const body: Record<string, unknown> = { first_name: v.first_name, last_name: v.last_name || null, phone: v.phone };
      if (v.role !== u.role) body.role = v.role;
      const r = await call("PATCH", `users/${u.id}`, body);
      if (!r.ok) return errorText(r.data);
      setRows((l) => l.map((x) => (x.id === u.id ? { ...x, ...r.data.user } : x)));
      toast.success("Saqlandi");
      router.refresh();
    },
  });

  const password = (u: UserRow) => prompt({
    title: "Yangi parol",
    description: `${personName(u)} — barcha qurilmalardan chiqariladi.`,
    fields: [{ name: "password", label: "Parol", type: "password", required: true, autoComplete: "new-password" }],
    onSubmit: async (v) => {
      const r = await call("PATCH", `users/${u.id}`, { password: v.password });
      if (!r.ok) return errorText(r.data);
      toast.success("Parol o'rnatildi");
    },
  });

  async function toggleActive(u: UserRow) {
    setRows((l) => l.map((x) => (x.id === u.id ? { ...x, active: !u.active } : x)));
    const r = await call("PATCH", `users/${u.id}`, { active: !u.active });
    if (!r.ok) {
      setRows((l) => l.map((x) => (x.id === u.id ? { ...x, active: u.active } : x)));
      return toast.error("Saqlanmadi", errorText(r.data));
    }
    toast.success(u.active ? "Foydalanuvchi bloklandi" : "Foydalanuvchi faollashtirildi", personName(u));
  }

  return (
    <>
      <PageHeader title="Foydalanuvchilar" description={`Jami: ${meta.total}`} actions={<Button icon={<UserPlus />} onClick={addUser}>Qo&apos;shish</Button>} />
      <div className="row" style={{ marginBottom: 16, alignItems: "flex-start" }}>
        <Tabs
          variant="pills"
          label="Rol bo'yicha"
          value={role || "all"}
          onChange={(id) => go({ role: id === "all" ? "" : id, page: "" })}
          items={[{ id: "all", label: "Barchasi" }, ...roles.map((r) => ({ id: r, label: roleNames[r] ?? r }))]}
        />
        <div style={{ flex: "0 1 320px", minWidth: 220 }}>
          <Input type="search" placeholder="Ism yoki telefon…" aria-label="Qidirish" value={search} onChange={(e) => setSearch(e.target.value)} leading={<Search />} />
        </div>
      </div>
      <Card style={{ opacity: loading ? 0.6 : 1, transition: "opacity 150ms" }} aria-busy={loading}>
        {rows.length === 0 ? (
          <EmptyState icon={<UsersIcon />} title="Hech kim topilmadi">{q ? "Qidiruv so'zini o'zgartirib ko'ring." : "Bu rolda foydalanuvchi yo'q."}</EmptyState>
        ) : (
          <Table stack caption="Foydalanuvchilar">
            <thead><tr><Th>Foydalanuvchi</Th><Th>Telefon</Th><Th>Rol</Th><Th>Holat</Th><Th actions><span className="sr-only">Amallar</span></Th></tr></thead>
            <tbody>
              {rows.map((u) => (
                <tr key={u.id}>
                  <Td>
                    <span style={{ display: "inline-flex", alignItems: "center", gap: 10 }}>
                      <Avatar name={personName(u)} size={30} />
                      <strong style={{ fontWeight: 600 }}>{personName(u)}</strong>
                      {u.id === selfId && <Badge tone="accent">Siz</Badge>}
                    </span>
                  </Td>
                  <Td label="Telefon" className="mono">{u.phone}</Td>
                  <Td label="Rol"><Badge tone={ROLE_TONE[u.role] ?? "neutral"}>{roleNames[u.role] ?? u.role}</Badge></Td>
                  <Td label="Holat">{u.active ? <Badge tone="success" dot>Faol</Badge> : <Badge tone="danger" dot>Bloklangan</Badge>}</Td>
                  <Td actions>
                    {canEdit(u) && (
                      <Dropdown
                        trigger={(p) => <Button size="sm" variant="ghost" iconOnly aria-label={`${personName(u)} — amallar`} icon={<MoreHorizontal />} {...p} />}
                        items={[
                          { label: "Tahrirlash", icon: <Pencil />, onSelect: () => edit(u) },
                          { label: "Parol o'rnatish", icon: <KeyRound />, onSelect: () => password(u) },
                          { type: "separator" as const },
                          u.active
                            ? { label: "Bloklash", icon: <ShieldOff />, danger: true, onSelect: () => toggleActive(u) }
                            : { label: "Faollashtirish", icon: <ShieldCheck />, onSelect: () => toggleActive(u) },
                        ]}
                      />
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {meta.pages > 1 && (
        <nav className="row" style={{ justifyContent: "center", marginTop: 16 }} aria-label="Sahifalar">
          <Button variant="secondary" size="sm" icon={<ChevronLeft />} disabled={meta.page <= 1} onClick={() => go({ page: String(meta.page - 1) })}>Oldingi</Button>
          <span className="muted" aria-current="page">{meta.page} / {meta.pages}</span>
          <Button variant="secondary" size="sm" disabled={meta.page >= meta.pages} onClick={() => go({ page: String(meta.page + 1) })}>Keyingi<ChevronRight size={16} aria-hidden /></Button>
        </nav>
      )}
    </>
  );
}
