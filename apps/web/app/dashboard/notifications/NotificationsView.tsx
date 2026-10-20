"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { AlertTriangle, Bell, CheckCheck, Download, Send, UserX } from "lucide-react";
import { Badge, Button, Card, CardHeader, EmptyState, Field, Input, PageHeader, Select, StatCard, Switch, Table, Tabs, Td, Th, buttonClass, cx } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { ROLE_LABELS } from "@/components/shell/nav";
import { call, errorText } from "@/lib/client";
import { fmtDateTime } from "@/lib/format";
import type { InboxItem } from "@/lib/notifications";
import { obj, timezone } from "@/lib/settings";
import s from "./notifications.module.css";

const STATUSES = ["sent", "failed", "not_linked", "blocked", "skipped", "not_configured"] as const;
const DEFAULT_LABELS: Record<string, string> = {
  sent: "Yetkazildi", failed: "Xato", not_linked: "Telegram ulanmagan", blocked: "Botni bloklagan", skipped: "O'tkazib yuborilgan", not_configured: "Bot sozlanmagan",
};

export default function NotificationsView({ admin, initialTab }: { admin: boolean; initialTab: string }) {
  const [tab, setTab] = useState(initialTab);
  return (
    <>
      <PageHeader title="Bildirishnomalar" description={admin ? "Sizga kelgan xabarlar va Telegram orqali yetkazish hisoboti" : "Sizga yuborilgan xabarlar"} />
      {admin && <Tabs label="Bo'limlar" value={tab} onChange={setTab} items={[{ id: "inbox", label: "Mening xabarlarim", icon: <Bell /> }, { id: "report", label: "Yetkazish hisoboti", icon: <Send /> }]} />}
      {tab === "report" && admin ? <Report /> : <Inbox />}
    </>
  );
}

function Inbox() {
  const st = useSiteSettings();
  const tz = timezone(st);
  const delivery = { ...DEFAULT_LABELS, ...obj<Record<string, string>>(st, "notifications.delivery_labels") };
  const [onlyUnread, setOnlyUnread] = useState(false);
  const [page, setPage] = useState(1);
  const [data, setData] = useState<{ data: InboxItem[]; meta: { page: number; pages: number; total: number; unread: number; news_unread: number } } | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    const r = await call<NonNullable<typeof data>>("GET", `notifications?page=${page}${onlyUnread ? "&unread=1" : ""}`);
    if (!r.ok) return setError(errorText(r.data));
    setError(null);
    setData(r.data);
  }, [page, onlyUnread]);
  useEffect(() => { void load(); }, [load]);

  async function read(ids: number[] | "all") {
    const r = await call("POST", "notifications/read", ids === "all" ? { all: true } : { ids });
    if (r.ok) void load();
  }

  if (error) return <Card><EmptyState icon={<AlertTriangle />} title="Yuklanmadi">{error}</EmptyState></Card>;
  return (
    <Card>
      <div className={s.toolbar}>
        <Switch checked={onlyUnread} onChange={(e) => { setOnlyUnread(e.target.checked); setPage(1); }} label="Faqat o'qilmaganlar" />
        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
          {data && data.meta.news_unread > 0 && <Link className={buttonClass({ variant: "soft", size: "sm" })} href="/dashboard/news">{data.meta.news_unread} ta yangi yangilik</Link>}
          <Button size="sm" variant="secondary" icon={<CheckCheck />} disabled={!data?.meta.unread} onClick={() => read("all")}>Hammasini o&apos;qildi</Button>
        </div>
      </div>
      {!data ? <p className="subtle" style={{ padding: 16, margin: 0 }}>Yuklanmoqda…</p> : data.data.length === 0 ? (
        <EmptyState icon={<Bell />} title={onlyUnread ? "O'qilmagan xabar yo'q" : "Hali xabar yo'q"}>Uyga vazifa, baho, davomat va to&apos;lov xabarlari shu yerda ko&apos;rinadi.</EmptyState>
      ) : (
        <ul className={s.list}>
          {data.data.map((n) => (
            <li key={n.id} className={cx(s.item, !n.read && s.unread)}>
              <span className={s.dot} aria-hidden />
              <div className={s.main}>
                <div className={s.head}>
                  <Badge tone="accent">{n.label}</Badge>
                  <time>{fmtDateTime(n.created_at, tz)}</time>
                  {n.delivery !== "sent" && <Badge tone={n.delivery === "failed" || n.delivery === "blocked" ? "danger" : "neutral"}>Telegram: {delivery[n.delivery] ?? n.delivery}</Badge>}
                  {!n.read && <span className="sr-only">O&apos;qilmagan</span>}
                </div>
                <p className={s.text}>{n.text}</p>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  {n.url && <Link className="a" href={n.url} onClick={() => !n.read && void read([n.id])}>Ochish</Link>}
                  {!n.read && <button type="button" className="a" style={{ background: "none", border: 0, padding: 0, cursor: "pointer", font: "inherit" }} onClick={() => read([n.id])}>O&apos;qildi deb belgilash</button>}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
      {data && data.meta.pages > 1 && (
        <div className={s.pager}>
          <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Oldingi</Button>
          <span className="subtle">{data.meta.page} / {data.meta.pages}</span>
          <Button size="sm" variant="secondary" disabled={page >= data.meta.pages} onClick={() => setPage(page + 1)}>Keyingi</Button>
        </div>
      )}
    </Card>
  );
}

type ReportData = {
  period: { from: string; to: string };
  total: { counts: Record<string, number>; total: number; delivered_percent: number | null };
  by_kind: { kind: string; label: string; counts: Record<string, number>; total: number; delivered_percent: number | null }[];
  reach: { role: string; total: number; reachable: number; percent: number | null }[];
};
type Unreachable = { id: number; name: string; phone: string; role: string; reason: "not_linked" | "blocked"; groups: string[] };

function Report() {
  const st = useSiteSettings();
  const labels = { ...DEFAULT_LABELS, ...obj<Record<string, string>>(st, "notifications.delivery_labels") };
  const roles = { ...ROLE_LABELS, ...obj<Record<string, string>>(st, "telegram.role_labels") };
  const [p, setP] = useState({ from: "", to: "" });
  const [data, setData] = useState<ReportData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [uf, setUf] = useState({ role: "", reason: "" });
  const [page, setPage] = useState(1);
  const [un, setUn] = useState<{ data: Unreachable[]; meta: { page: number; pages: number; total: number } } | null>(null);

  useEffect(() => {
    const qs = new URLSearchParams(Object.entries(p).filter(([, v]) => v) as [string, string][]).toString();
    call<ReportData>("GET", `notifications/report${qs ? `?${qs}` : ""}`).then((r) => { if (r.ok) { setData(r.data); setError(null); } else setError(errorText(r.data)); });
  }, [p]);
  const uqs = new URLSearchParams(Object.entries(uf).filter(([, v]) => v) as [string, string][]).toString();
  useEffect(() => {
    call<NonNullable<typeof un>>("GET", `notifications/unreachable?page=${page}${uqs ? `&${uqs}` : ""}`).then((r) => r.ok && setUn(r.data));
  }, [uqs, page]);

  if (error) return <Card><EmptyState icon={<AlertTriangle />} title="Hisobot olinmadi">{error}</EmptyState></Card>;
  return (
    <div style={{ display: "grid", gap: "var(--space-4)" }}>
      <Card className={s.filters}>
        <Field label="Boshlanish"><Input type="date" value={p.from || data?.period.from || ""} onChange={(e) => setP({ ...p, from: e.target.value })} /></Field>
        <Field label="Tugash"><Input type="date" value={p.to || data?.period.to || ""} onChange={(e) => setP({ ...p, to: e.target.value })} /></Field>
      </Card>
      {data && (
        <>
          <div className="stats-grid" style={{ marginBottom: 0 }}>
            <StatCard label="Yetkazildi" value={data.total.delivered_percent != null ? `${data.total.delivered_percent}%` : "—"} progress={data.total.delivered_percent} icon={<Send />}
              hint={`${data.total.counts.sent ?? 0} / ${data.total.total - (data.total.counts.skipped ?? 0)} ta xabar`} tone={(data.total.delivered_percent ?? 100) >= 90 ? "success" : "warning"} />
            {data.reach.filter((r) => r.total > 0).map((r) => (
              <StatCard key={r.role} label={`Qamrov: ${roles[r.role] ?? r.role}`} value={r.percent != null ? `${r.percent}%` : "—"} progress={r.percent}
                hint={`${r.reachable} / ${r.total} kishiga Telegram orqali yetadi`} tone={(r.percent ?? 0) >= 90 ? "success" : (r.percent ?? 0) >= 70 ? "warning" : "danger"} />
            ))}
          </div>
          <Card>
            <CardHeader title="Turlar bo'yicha" description={`${data.period.from} — ${data.period.to}`} />
            {data.by_kind.length === 0 ? <EmptyState compact icon={<Send />} title="Bu davrda xabar yuborilmagan" /> : (
              <>
                <Table stack caption="Yetkazish turlar bo'yicha">
                  <thead><tr><Th>Tur</Th><Th>Taqsimot</Th>{STATUSES.map((k) => <Th key={k} numeric>{labels[k]}</Th>)}<Th numeric>Yetkazildi</Th></tr></thead>
                  <tbody>
                    {data.by_kind.map((k) => (
                      <tr key={k.kind}>
                        <Td><b>{k.label}</b></Td>
                        <Td label="Taqsimot">
                          <div className={s.bar} role="img" aria-label={STATUSES.map((x) => `${labels[x]}: ${k.counts[x] ?? 0}`).join(", ")}>
                            {STATUSES.map((x) => (k.counts[x] ?? 0) > 0 && <span key={x} className={s[`s-${x}`]} style={{ width: `${((k.counts[x] ?? 0) / k.total) * 100}%` }} />)}
                          </div>
                        </Td>
                        {STATUSES.map((x) => <Td key={x} numeric label={labels[x]}>{k.counts[x] ?? 0}</Td>)}
                        <Td numeric label="Yetkazildi">{k.delivered_percent != null ? `${k.delivered_percent}%` : "—"}</Td>
                      </tr>
                    ))}
                  </tbody>
                </Table>
                <div className={s.legend} aria-hidden>{STATUSES.map((x) => <span key={x}><i className={s[`s-${x}`]} />{labels[x]}</span>)}</div>
              </>
            )}
          </Card>
        </>
      )}
      <Card>
        <CardHeader title="Telegram orqali yetib bo'lmaydiganlar" description="Ulanmagan yoki botni bloklagan faol foydalanuvchilar — telefon orqali bog'laning"
          actions={<a className={buttonClass({ variant: "secondary", size: "sm" })} href={`/api/export/notifications/unreachable.csv${uqs ? `?${uqs}` : ""}`} download><Download aria-hidden /> CSV</a>} />
        <div className={s.filters} style={{ paddingTop: 0 }}>
          <Field label="Rol"><Select size="sm" value={uf.role} onChange={(e) => { setUf({ ...uf, role: e.target.value }); setPage(1); }}>
            <option value="">Barchasi</option>{["STUDENT", "TEACHER", "ADMIN", "SUPER_ADMIN"].map((r) => <option key={r} value={r}>{roles[r] ?? r}</option>)}
          </Select></Field>
          <Field label="Sabab"><Select size="sm" value={uf.reason} onChange={(e) => { setUf({ ...uf, reason: e.target.value }); setPage(1); }}>
            <option value="">Barchasi</option><option value="not_linked">{labels.not_linked}</option><option value="blocked">{labels.blocked}</option>
          </Select></Field>
        </div>
        {!un ? <p className="subtle" style={{ padding: 16, margin: 0 }}>Yuklanmoqda…</p> : un.data.length === 0 ? <EmptyState compact icon={<UserX />} title="Hammaga Telegram orqali yetib boriladi 🎉" /> : (
          <>
            <Table stack caption="Yetib bo'lmaydiganlar">
              <thead><tr><Th>Ism</Th><Th>Telefon</Th><Th>Rol</Th><Th>Sabab</Th><Th>Guruhlar</Th></tr></thead>
              <tbody>
                {un.data.map((u) => (
                  <tr key={u.id}>
                    <Td><b>{u.name}</b></Td>
                    <Td label="Telefon"><a className="a" href={`tel:${u.phone}`}>{u.phone}</a></Td>
                    <Td label="Rol">{roles[u.role] ?? u.role}</Td>
                    <Td label="Sabab"><Badge tone={u.reason === "blocked" ? "danger" : "warning"}>{labels[u.reason]}</Badge></Td>
                    <Td label="Guruhlar">{u.groups.join(", ") || "—"}</Td>
                  </tr>
                ))}
              </tbody>
            </Table>
            {un.meta.pages > 1 && (
              <div className={s.pager}>
                <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Oldingi</Button>
                <span className="subtle">{un.meta.page} / {un.meta.pages} · {un.meta.total} ta</span>
                <Button size="sm" variant="secondary" disabled={page >= un.meta.pages} onClick={() => setPage(page + 1)}>Keyingi</Button>
              </div>
            )}
          </>
        )}
      </Card>
    </div>
  );
}
