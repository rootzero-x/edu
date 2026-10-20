"use client";

import { useEffect, useState, useTransition } from "react";
import { usePathname, useRouter } from "next/navigation";
import Link from "next/link";
import { Check, ChevronLeft, ChevronRight, Copy, FileSpreadsheet, GitMerge, KeyRound, MoreHorizontal, Pencil, Search, ShieldOff, ShieldCheck, UserPlus, UserRound, Users as UsersIcon, X } from "lucide-react";
import { Avatar, Badge, Button, Card, Dialog, Dropdown, EmptyState, Input, PageHeader, Table, Tabs, Td, Th, type Tone, useConfirm, usePrompt, useToast } from "@/components/ui";
import ImportDialog from "./ImportDialog";
import { useSiteSettings } from "@/components/SiteProvider";
import { obj } from "@/lib/settings";
import { ROLE_LABELS } from "@/components/shell/nav";
import { call, errorText } from "@/lib/client";
import { fmtPhone, normalizePhone, personName } from "@/lib/format";

export type UserRow = {
  id: number; phone: string; first_name: string; last_name: string | null; role: string; active: boolean;
  pending?: boolean; telegram?: { linked: boolean; blocked: boolean; username?: string | null };
};
type DupUser = { id: number; phone: string; name: string; role: string; active: boolean; pending: boolean; history: boolean };
type Duplicates = { phone_groups: { phone: string; users: DupUser[] }[]; name_groups: { name: string; users: DupUser[] }[]; non_canonical: (DupUser & { normalized: string | null })[] };
/** Ro'yxat ko'rinishi: hammasi, arizalar, Telegram ulanmagan, botni bloklagan. */
const VIEWS = [
  { id: "all", label: "Hammasi" }, { id: "pending", label: "Arizalar" },
  { id: "tg_none", label: "Telegram ulanmagan" }, { id: "tg_blocked", label: "Botni bloklagan" },
];
/** Ariza orqali beriladigan rollar (backend Registration::ROLES). */
const APPLICANT_ROLES = ["STUDENT", "TEACHER"];
const ROLES = ["STUDENT", "TEACHER", "ADMIN", "SUPER_ADMIN"];
const ROLE_TONE: Record<string, Tone> = { STUDENT: "info", TEACHER: "accent", ADMIN: "warning", SUPER_ADMIN: "danger" };

export default function Users({ rows: initialRows, meta, role, q, view, actorRole, selfId, phoneHint, openImport = false, importGroup }: {
  rows: UserRow[]; meta: { total: number; page: number; pages: number; pending?: number }; role: string; q: string; view: string;
  actorRole: string; selfId: number; phoneHint: string;
  openImport?: boolean; importGroup?: number;
}) {
  // Rol nomlari sozlamalardan (telegram.role_labels) — bot, Mini App va sayt bir xil ko'rsatadi.
  const roleNames = { ...ROLE_LABELS, ...obj<Record<string, string>>(useSiteSettings(), "telegram.role_labels") };
  const [importing, setImporting] = useState(openImport);
  useEffect(() => setImporting(openImport), [openImport]);
  const router = useRouter();
  const path = usePathname();
  const toast = useToast();
  const prompt = usePrompt();
  const confirm = useConfirm();
  const [rows, setRows] = useState(initialRows);
  const [search, setSearch] = useState(q);
  const [loading, startTransition] = useTransition();
  const [dups, setDups] = useState<Duplicates | null>(null);
  useEffect(() => setRows(initialRows), [initialRows]);

  const roles = ROLES.filter((r) => r !== "SUPER_ADMIN" || actorRole === "SUPER_ADMIN");
  const go = (params: Record<string, string>) => {
    const sp = new URLSearchParams({ role, q, view, ...params });
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
      { name: "phone", label: "Telefon", type: "tel", defaultValue: "+998", required: true, placeholder: phoneHint, hint: "Istalgan yozuvda: 90 123 45 67 ham bo'ladi", autoComplete: "tel" },
      { name: "first_name", label: "Ism", required: true },
      { name: "last_name", label: "Familiya" },
      { name: "role", label: "Rol", type: "select", required: true, defaultValue: role || "STUDENT", options: roles.map((r) => ({ value: r, label: roleNames[r] ?? r })) },
      { name: "password", label: "Parol", type: "password", hint: "Ixtiyoriy — Telegram orqali kirish uchun shart emas", autoComplete: "new-password" },
    ],
    submitText: "Qo'shish",
    onSubmit: async (v) => {
      const phone = normalizePhone(v.phone);
      const r = await call("POST", "users", { ...v, phone, last_name: v.last_name || null, password: v.password || undefined });
      if (!r.ok) return errorText(r.data);
      toast.success("Foydalanuvchi qo'shildi", `${v.first_name} · ${fmtPhone(r.data.user?.phone ?? phone)}`);
      router.refresh();
    },
  });

  const edit = (u: UserRow) => prompt({
    title: "Ma'lumotlarni tahrirlash",
    fields: [
      { name: "first_name", label: "Ism", required: true, defaultValue: u.first_name },
      { name: "last_name", label: "Familiya", defaultValue: u.last_name ?? "" },
      { name: "phone", label: "Telefon", type: "tel", required: true, defaultValue: u.phone, placeholder: phoneHint },
      { name: "role", label: "Rol", type: "select", required: true, defaultValue: u.role, options: roles.map((r) => ({ value: r, label: roleNames[r] ?? r })) },
    ],
    onSubmit: async (v) => {
      const body: Record<string, unknown> = { first_name: v.first_name, last_name: v.last_name || null, phone: normalizePhone(v.phone) };
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

  async function approve(u: UserRow, asRole: string) {
    const r = await call("POST", `users/${u.id}/approve`, { role: asRole });
    if (!r.ok) return toast.error("Tasdiqlanmadi", errorText(r.data));
    toast.success("Ariza tasdiqlandi", `${personName(u)} — ${roleNames[asRole] ?? asRole}`);
    router.refresh();
  }

  async function reject(u: UserRow) {
    if (!(await confirm({ title: "Ariza rad etilsinmi?", description: `${personName(u)} (${fmtPhone(u.phone)}) akkaunti faol bo'lmaydi; unga Telegram orqali xabar boradi.`, confirmText: "Rad etish", tone: "danger" }))) return;
    const r = await call("POST", `users/${u.id}/reject`);
    if (!r.ok) return toast.error("Bajarilmadi", errorText(r.data));
    toast.success("Ariza rad etildi", personName(u));
    router.refresh();
  }

  async function openDuplicates() {
    const r = await call<Duplicates>("GET", "users/duplicates");
    if (!r.ok) return toast.error("Yuklanmadi", errorText(r.data));
    setDups(r.data);
  }

  /** Tarixsiz takrorni guruhdagi asosiy akkauntga qo'shish (backend tarixlisini qabul qilmaydi). */
  async function merge(from: DupUser, into: DupUser) {
    if (!(await confirm({
      title: "Akkauntlar birlashtirilsinmi?",
      description: `#${from.id} ${from.name} (${fmtPhone(from.phone)}) → #${into.id} ${into.name}. Telegram bog'lanishi ko'chiriladi, takror akkaunt arxivlanadi.`,
      confirmText: "Birlashtirish",
    }))) return;
    const r = await call("POST", `users/${from.id}/merge`, { into_id: into.id });
    if (!r.ok) return toast.error("Birlashtirilmadi", errorText(r.data));
    toast.success("Birlashtirildi");
    void openDuplicates();
    router.refresh();
  }

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
      <PageHeader title="Foydalanuvchilar" description={`Jami: ${meta.total}`} actions={<>
        <Button variant="secondary" icon={<Copy />} onClick={openDuplicates}>Takrorlar</Button>
        <Button variant="secondary" icon={<FileSpreadsheet />} onClick={() => setImporting(true)}>Import</Button>
        <Button icon={<UserPlus />} onClick={addUser}>Qo&apos;shish</Button>
      </>} />
      <div style={{ marginBottom: 12 }}>
        <Tabs
          label="Holat bo'yicha"
          value={view || "all"}
          onChange={(id) => go({ view: id === "all" ? "" : id, page: "" })}
          items={VIEWS.map((v) => ({ ...v, count: v.id === "pending" && meta.pending ? meta.pending : undefined }))}
        />
      </div>
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
          <EmptyState icon={<UsersIcon />} title="Hech kim topilmadi">{q ? "Qidiruv so'zini o'zgartirib ko'ring." : view === "pending" ? "Ko'rib chiqilmagan ariza yo'q." : "Bu ro'yxatda foydalanuvchi yo'q."}</EmptyState>
        ) : (
          <Table stack caption="Foydalanuvchilar">
            <thead><tr><Th>Foydalanuvchi</Th><Th>Telefon</Th><Th>Rol</Th><Th>Holat</Th><Th>Telegram</Th><Th actions><span className="sr-only">Amallar</span></Th></tr></thead>
            <tbody>
              {rows.map((u) => (
                <tr key={u.id}>
                  <Td>
                    <span style={{ display: "inline-flex", alignItems: "center", gap: 10 }}>
                      <Avatar name={personName(u)} size={30} />
                      <Link href={`/dashboard/users/${u.id}`} className="a"><strong style={{ fontWeight: 600 }}>{personName(u)}</strong></Link>
                      {u.id === selfId && <Badge tone="accent">Siz</Badge>}
                    </span>
                  </Td>
                  <Td label="Telefon" className="mono">{fmtPhone(u.phone)}</Td>
                  <Td label="Rol"><Badge tone={ROLE_TONE[u.role] ?? "neutral"}>{roleNames[u.role] ?? u.role}</Badge></Td>
                  <Td label="Holat">{u.pending ? <Badge tone="warning" dot>Ariza</Badge> : u.active ? <Badge tone="success" dot>Faol</Badge> : <Badge tone="danger" dot>Bloklangan</Badge>}</Td>
                  <Td label="Telegram">
                    {!u.telegram?.linked ? <span className="subtle">Ulanmagan</span>
                      : u.telegram.blocked ? <Badge tone="danger">Botni bloklagan</Badge>
                      : <Badge tone="info">{u.telegram.username ? `@${u.telegram.username}` : "Ulangan"}</Badge>}
                  </Td>
                  <Td actions>
                    {u.pending && (
                      <span className="row" style={{ gap: 6, justifyContent: "flex-end" }}>
                        <Dropdown
                          trigger={(p) => <Button size="sm" icon={<Check />} {...p}>Tasdiqlash</Button>}
                          items={APPLICANT_ROLES.map((r) => ({ label: `${roleNames[r] ?? r} sifatida`, onSelect: () => approve(u, r) }))}
                        />
                        <Button size="sm" variant="ghost" iconOnly icon={<X />} aria-label={`${personName(u)} — arizani rad etish`} onClick={() => reject(u)} />
                      </span>
                    )}
                    {!u.pending && canEdit(u) && (
                      <Dropdown
                        trigger={(p) => <Button size="sm" variant="ghost" iconOnly aria-label={`${personName(u)} — amallar`} icon={<MoreHorizontal />} {...p} />}
                        items={[
                          { label: "Profil", icon: <UserRound />, href: `/dashboard/users/${u.id}` },
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
      <Dialog open={dups !== null} onClose={() => setDups(null)} title="Takroriy akkauntlar" icon={<Copy />}
        description="Bir xil telefon (har xil yozuvda) yoki bir xil ism-familiya. Tarixi yo'q takrorni asosiy akkauntga qo'shish mumkin.">
        {dups && (dups.phone_groups.length === 0 && dups.name_groups.length === 0 && dups.non_canonical.length === 0
          ? <EmptyState compact icon={<Check />} title="Takror topilmadi" />
          : (
            <div style={{ display: "grid", gap: 16 }}>
              {dups.phone_groups.map((g) => {
                const main = g.users.find((x) => x.history) ?? g.users[0];
                return (
                  <section key={g.phone}>
                    <h3 style={{ margin: "0 0 6px", fontSize: 14 }}>{fmtPhone(g.phone)}</h3>
                    <ul style={{ listStyle: "none", margin: 0, padding: 0, display: "grid", gap: 6 }}>
                      {g.users.map((d) => (
                        <li key={d.id} className="row" style={{ gap: 8 }}>
                          <span style={{ flex: 1 }}>#{d.id} <b>{d.name}</b> <span className="subtle mono">{d.phone}</span> {d.history && <Badge tone="info">tarix bor</Badge>}</span>
                          {d.id !== main.id && !d.history && (
                            <Button size="sm" variant="secondary" icon={<GitMerge />} onClick={() => merge(d, main)}>#{main.id} ga qo&apos;shish</Button>
                          )}
                        </li>
                      ))}
                    </ul>
                  </section>
                );
              })}
              {dups.name_groups.length > 0 && (
                <section>
                  <h3 style={{ margin: "0 0 6px", fontSize: 14 }}>Bir xil ism-familiya (tekshirib ko&apos;ring)</h3>
                  <ul style={{ margin: 0, paddingLeft: 18 }}>
                    {dups.name_groups.map((g) => <li key={g.name}>{g.users[0]?.name}: {g.users.map((d) => `#${d.id} ${fmtPhone(d.phone)}`).join(", ")}</li>)}
                  </ul>
                </section>
              )}
              {dups.non_canonical.length > 0 && (
                <p className="subtle" style={{ margin: 0 }}>
                  {dups.non_canonical.length} ta raqam eski formatda saqlangan — serverda <code>php artisan tisu:users-duplicates --fix</code> bilan to&apos;g&apos;rilanadi.
                </p>
              )}
            </div>
          ))}
      </Dialog>
      <ImportDialog open={importing} onClose={() => setImporting(false)} onDone={() => router.refresh()} defaultGroupId={importGroup} />
    </>
  );
}

