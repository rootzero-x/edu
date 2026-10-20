"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Eye, EyeOff, Megaphone, MoreHorizontal, Pencil, Pin, Plus, Send, Trash2 } from "lucide-react";
import {
  Badge, Button, Card, CardBody, Dialog, Dropdown, EmptyState, Field, Input, PageHeader, Select, Switch, Tabs, Textarea, cx,
  useConfirm, useToast,
} from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDate } from "@/lib/format";
import { obj, str, timezone } from "@/lib/settings";
import s from "./news.module.css";

export type NewsItem = {
  id: number; title: string; body: string; image_url: string | null; audience: "ALL" | "ROLE" | "GROUP"; audience_role: string | null;
  group: { id: number; name: string } | null; is_public: boolean; pinned: boolean; notify_telegram: boolean;
  published_at: string | null; notified_at: string | null; author: string | null; read?: boolean;
};
type Draft = { title: string; body: string; image_url: string; audience: string; audience_role: string; group_id: string; is_public: boolean; pinned: boolean; notify_telegram: boolean };

const ROLES = ["STUDENT", "PARENT", "TEACHER", "ADMIN"];

export default function NewsView({ feed, unread: initialUnread, managed, groups, isAdmin }: {
  feed: NewsItem[]; unread: number; managed: NewsItem[] | null; groups: { id: number; name: string }[]; isAdmin: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const confirm = useConfirm();
  const st = useSiteSettings();
  const tz = timezone(st);
  const roleNames = obj<Record<string, string>>(st, "telegram.role_labels");
  const [tab, setTab] = useState("feed");
  const [open, setOpen] = useState<number | null>(null);
  const [read, setRead] = useState<Set<number>>(new Set());
  const [unread, setUnread] = useState(initialUnread);
  const [editing, setEditing] = useState<NewsItem | "new" | null>(null);
  useEffect(() => setUnread(initialUnread), [initialUnread]);

  function expand(a: NewsItem) {
    setOpen((o) => (o === a.id ? null : a.id));
    if (!a.read && !read.has(a.id)) {
      setRead((r) => new Set(r).add(a.id));
      call<{ unread: number }>("POST", `announcements/${a.id}/read`).then((r) => r.ok && setUnread(r.data.unread));
    }
  }

  async function publish(a: NewsItem, on: boolean) {
    const r = await call<{ notified: number }>("POST", `announcements/${a.id}/publish`, { published: on });
    if (!r.ok) return toast.error("Bajarilmadi", errorText(r.data));
    toast.success(on ? "E'lon chop etildi" : "E'lon yashirildi", on && r.data.notified ? `${r.data.notified} kishiga Telegram xabari yuborilmoqda` : undefined);
    router.refresh();
  }

  async function remove(a: NewsItem) {
    if (!(await confirm({ title: "E'lon o'chirilsinmi?", description: a.title, confirmText: "O'chirish", tone: "danger" }))) return;
    const r = await call("DELETE", `announcements/${a.id}`);
    if (!r.ok) return toast.error("O'chirilmadi", errorText(r.data));
    toast.success("E'lon o'chirildi");
    router.refresh();
  }

  const audienceText = (a: NewsItem) =>
    a.audience === "ALL" ? "Hammaga" : a.audience === "ROLE" ? (roleNames[a.audience_role ?? ""] ?? a.audience_role) : a.group?.name ?? "Guruh";

  const card = (a: NewsItem, admin: boolean) => {
    const isNew = !admin && !a.read && !read.has(a.id);
    const expanded = admin || open === a.id;
    return (
      <Card key={a.id} className={cx(s.card, isNew && s.unread)}>
        <CardBody>
          <div className={cx(s.head, admin && s.headAdmin)}>
            <button type="button" className={s.title} onClick={() => !admin && expand(a)} aria-expanded={admin ? undefined : expanded}>
              {a.pinned && <Pin aria-label="Muhim" className={s.pin} />}{a.title}
            </button>
            <span className={s.meta}>
              {isNew && <Badge tone="accent" dot>Yangi</Badge>}
              {admin && (a.published_at ? <Badge tone="success">Chop etilgan</Badge> : <Badge tone="warning">Qoralama</Badge>)}
              {admin && <Badge>{audienceText(a)}</Badge>}
              {admin && a.is_public && <Badge tone="info">Bosh sahifada</Badge>}
            </span>
            {admin && (
              <span className={s.menu}><Dropdown
                trigger={(p) => <Button variant="ghost" size="sm" iconOnly aria-label="E'lon amallari" icon={<MoreHorizontal />} {...p} />}
                items={[
                  a.published_at
                    ? { label: "Yashirish", icon: <EyeOff />, onSelect: () => publish(a, false) }
                    : { label: "Chop etish", icon: <Send />, onSelect: () => publish(a, true) },
                  { label: "Tahrirlash", icon: <Pencil />, onSelect: () => setEditing(a) },
                  { type: "separator" },
                  { label: "O'chirish", icon: <Trash2 />, danger: true, onSelect: () => remove(a) },
                ]}
              /></span>
            )}
          </div>
          <div className="subtle">{a.published_at ? fmtDate(a.published_at, tz) : "Chop etilmagan"}{a.author ? ` · ${a.author}` : ""}</div>
          {expanded && a.image_url && <img src={a.image_url} alt="" className={s.img} />}
          <p className={cx(s.body, !expanded && s.clamp)}>{a.body}</p>
          {!admin && !expanded && <button type="button" className={s.more} onClick={() => expand(a)}><Eye aria-hidden /> To&apos;liq o&apos;qish</button>}
        </CardBody>
      </Card>
    );
  };

  return (
    <>
      <PageHeader
        title={str(st, "news.title", "Yangiliklar")}
        description={unread ? `${unread} ta o'qilmagan` : "Barchasi o'qilgan"}
        actions={managed && <Button icon={<Plus />} onClick={() => setEditing("new")}>E&apos;lon</Button>}
      />
      {managed && (
        <div style={{ marginBottom: 16 }}>
          <Tabs value={tab} onChange={setTab} items={[
            { id: "feed", label: "Lenta", icon: <Megaphone />, count: unread || undefined },
            { id: "manage", label: "Mening e'lonlarim", icon: <Pencil />, count: managed.length },
          ]} />
        </div>
      )}
      <div className={s.list}>
        {tab === "feed" && (feed.length === 0
          ? <Card><EmptyState icon={<Megaphone />} title="Hozircha yangiliklar yo'q" /></Card>
          : feed.map((a) => card(a, false)))}
        {tab === "manage" && managed && (managed.length === 0
          ? <Card><EmptyState icon={<Megaphone />} title="Hali e'lon yo'q" action={<Button icon={<Plus />} onClick={() => setEditing("new")}>Birinchi e&apos;lon</Button>} /></Card>
          : managed.map((a) => card(a, true)))}
      </div>
      {editing && <Editor item={editing === "new" ? null : editing} groups={groups} isAdmin={isAdmin} roleNames={roleNames} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); setTab("manage"); router.refresh(); }} />}
    </>
  );
}

function Editor({ item, groups, isAdmin, roleNames, onClose, onSaved }: {
  item: NewsItem | null; groups: { id: number; name: string }[]; isAdmin: boolean; roleNames: Record<string, string>; onClose: () => void; onSaved: () => void;
}) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const [d, setD] = useState<Draft>(() => ({
    title: item?.title ?? "", body: item?.body ?? "", image_url: item?.image_url ?? "",
    audience: item?.audience ?? (isAdmin ? "ALL" : "GROUP"), audience_role: item?.audience_role ?? "STUDENT",
    group_id: item?.group ? String(item.group.id) : groups[0] ? String(groups[0].id) : "",
    is_public: item?.is_public ?? false, pinned: item?.pinned ?? false, notify_telegram: item?.notify_telegram ?? true,
  }));
  const set = (patch: Partial<Draft>) => setD((x) => ({ ...x, ...patch }));

  async function save(publishNow: boolean) {
    setBusy(true);
    const body = {
      title: d.title, body: d.body, image_url: d.image_url || null, audience: d.audience,
      audience_role: d.audience === "ROLE" ? d.audience_role : null,
      group_id: d.audience === "GROUP" ? Number(d.group_id) || null : null,
      is_public: d.audience === "ALL" && d.is_public, pinned: d.pinned, notify_telegram: d.notify_telegram,
    };
    const r = item
      ? await call("PATCH", `announcements/${item.id}`, body)
      : await call("POST", "announcements", { ...body, publish: publishNow });
    if (!r.ok) { setBusy(false); return toast.error("Saqlanmadi", errorText(r.data)); }
    if (item && publishNow && !item.published_at) {
      const p = await call("POST", `announcements/${item.id}/publish`, { published: true });
      if (!p.ok) { setBusy(false); toast.error("Saqlandi, lekin chop etilmadi", errorText(p.data)); return onSaved(); }
    }
    setBusy(false);
    toast.success(publishNow ? "E'lon chop etildi" : "Saqlandi");
    onSaved();
  }

  return (
    <Dialog open onClose={onClose} title={item ? "E'lonni tahrirlash" : "Yangi e'lon"} icon={<Megaphone />} width={620}
      footer={<>
        <Button variant="secondary" onClick={onClose}>Bekor</Button>
        {(!item || !item.published_at) && <Button variant="secondary" loading={busy} onClick={() => save(false)}>Qoralama</Button>}
        <Button icon={<Send />} loading={busy} onClick={() => save(true)} disabled={!d.title.trim() || !d.body.trim()}>{item?.published_at ? "Saqlash" : "Chop etish"}</Button>
      </>}>
      <div style={{ display: "grid", gap: 12 }}>
        <Field label="Sarlavha" required><Input value={d.title} maxLength={200} onChange={(e) => set({ title: e.target.value })} /></Field>
        <Field label="Matn" required><Textarea rows={6} value={d.body} maxLength={5000} onChange={(e) => set({ body: e.target.value })} /></Field>
        <Field label="Kimga">
          <Select value={d.audience} onChange={(e) => set({ audience: e.target.value })}>
            {isAdmin && <option value="ALL">Hammaga</option>}
            {isAdmin && <option value="ROLE">Rol bo&apos;yicha</option>}
            <option value="GROUP">Guruhga</option>
          </Select>
        </Field>
        {d.audience === "ROLE" && (
          <Field label="Rol"><Select value={d.audience_role} onChange={(e) => set({ audience_role: e.target.value })}>
            {ROLES.map((r) => <option key={r} value={r}>{roleNames[r] ?? r}</option>)}
          </Select></Field>
        )}
        {d.audience === "GROUP" && (
          <Field label="Guruh"><Select value={d.group_id} onChange={(e) => set({ group_id: e.target.value })}>
            {groups.map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
          </Select></Field>
        )}
        <Field label="Rasm (ixtiyoriy)" hint="https:// bilan boshlanadigan rasm havolasi"><Input type="url" value={d.image_url} placeholder="https://…" onChange={(e) => set({ image_url: e.target.value })} /></Field>
        <div style={{ display: "flex", flexWrap: "wrap", gap: "12px 24px" }}>
          <Switch label="Muhim (tepada)" checked={d.pinned} onChange={(e) => set({ pinned: e.target.checked })} />
          <Switch label="Telegram orqali yuborish" checked={d.notify_telegram} onChange={(e) => set({ notify_telegram: e.target.checked })} />
          {d.audience === "ALL" && <Switch label="Bosh sahifada ko'rsatish" checked={d.is_public} onChange={(e) => set({ is_public: e.target.checked })} />}
        </div>
        {item?.notified_at && <p className="subtle" style={{ margin: 0 }}>Telegram xabari allaqachon yuborilgan — qayta yuborilmaydi.</p>}
      </div>
    </Dialog>
  );
}
