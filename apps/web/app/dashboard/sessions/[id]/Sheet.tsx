"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { AlertCircle, Ban, CheckCheck, CheckCircle2, CircleDot, History, Loader2, Lock, MoreHorizontal, RotateCcw, Users } from "lucide-react";
import { Avatar, Badge, Button, Card, CardBody, Dialog, Dropdown, EmptyState, Field, Input, PageHeader, Progress, cx, useConfirm, useToast } from "@/components/ui";
import { fmtDateTime } from "@/lib/format";
import LiveStatus from "@/components/LiveStatus";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";
import { fmtDate, fmtTime, fmtWeekday } from "@/lib/format";
import { refreshSeconds, statusLabels, timezone } from "@/lib/settings";
import { STATUS_TONE } from "@/lib/status";
import { usePolling } from "@/lib/usePolling";
import s from "./sheet.module.css";

type Row = { student_id: number; name: string; status: string | null; arrived_at: string | null; note: string | null };
export type SheetData = {
  session: { id: number; group_id: number; starts_at: string; ends_at: string; topic: string | null; cancelled: boolean };
  group: { id: number; name: string };
  rows: Row[];
  /** Tahrir oynasi (server sozlamalari bo'yicha): o'tgan darslarni tuzatish muddati, sabab majburiyligi. */
  edit?: { can_edit: boolean; reason_required: boolean; message?: string; deadline?: string | null };
};
type HistoryItem = { id: number; at: string; by: string; reason: string | null; changes: { student_id: number; name: string; from: string | null; to: string }[] };
type Edit = { status?: string | null; arrived_at?: string; note?: string };
type SaveState = "idle" | "pending" | "saving" | "saved" | "error";

const SAVE_DELAY = 900;
const hhmm = (v: string | null) => (v ? v.slice(11, 16) : "");

/**
 * Davomat varaqasi:
 * - holat bosilganda darhol ekranda o'zgaradi (optimistic) va qisqa kutishdan so'ng avtomatik saqlanadi;
 * - xato bo'lsa o'zgarish qaytariladi va toast chiqadi;
 * - boshqa qurilmada belgilanganlar ui.refresh_seconds bo'yicha o'zi yangilanadi (tahrirlanayotgan qatorlar ustidan yozilmaydi).
 */
export default function Sheet({ initial, canMark: canMarkPerm }: { initial: SheetData; canMark: boolean }) {
  const st = useSiteSettings();
  const tz = timezone(st);
  const labels = statusLabels(st);
  const seconds = refreshSeconds(st);
  const toast = useToast();
  const confirm = useConfirm();

  const [server, setServer] = useState<SheetData>(initial);
  const [edits, setEdits] = useState<Record<number, Edit>>({});
  const [saving, setSaving] = useState<Set<number>>(new Set());
  const [failed, setFailed] = useState<Set<number>>(new Set());
  const [saveState, setSaveState] = useState<SaveState>("idle");
  const [topic, setTopic] = useState(initial.session.topic ?? "");
  const [reason, setReason] = useState("");
  const reasonRef = useRef(reason);
  reasonRef.current = reason;
  const [history, setHistory] = useState<HistoryItem[] | null>(null);
  const [loadedAt] = useState(() => Date.now());
  const editsRef = useRef(edits);
  editsRef.current = edits;
  const serverRef = useRef(server);
  serverRef.current = server;
  const version = useRef(0);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const { session, group, rows } = server;
  const started = new Date(session.starts_at).getTime() <= Date.now();
  const edit = server.edit;
  const canMark = canMarkPerm && !session.cancelled && started && (edit ? edit.can_edit : true);
  const reasonRequired = Boolean(edit?.reason_required);
  const isPast = new Date(session.ends_at).getTime() < Date.now();
  const statusKeys = Object.keys(labels);

  // ── Polling ───────────────────────────────────────────
  const fetcher = useCallback(async (signal: AbortSignal) => {
    const v = version.current;
    const r = await call<SheetData>("GET", `sessions/${initial.session.id}/attendance`, undefined, { signal });
    return r.ok ? { v, data: r.data } : null;
  }, [initial.session.id]);
  const live = usePolling(fetcher, ({ v, data }) => {
    // Shu so'rov davomida lokal saqlash bo'lgan bo'lsa — eski javobni tashlab yuboramiz.
    if (v === version.current) setServer(data);
  }, { seconds });

  const refreshNow = live.refresh;

  const value = (r: Row) => {
    const e = edits[r.student_id] ?? {};
    return {
      status: e.status !== undefined ? e.status : r.status,
      arrived: e.arrived_at ?? hhmm(r.arrived_at),
      note: e.note ?? r.note ?? "",
    };
  };

  // ── Saqlash ───────────────────────────────────────────
  const flush = useCallback(async () => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = null;
    const current = editsRef.current;
    const byId = new Map(serverRef.current.rows.map((r) => [r.student_id, r]));
    const items: { student_id: number; status: string | null; arrived_at: string | null; note: string | null }[] = [];
    const snapshot: Record<number, Edit> = {};
    for (const [key, e] of Object.entries(current)) {
      const id = Number(key);
      const r = byId.get(id);
      if (!r) continue;
      const arrived = e.arrived_at ?? hhmm(r.arrived_at);
      // Holat tanlanmagan, lekin vaqt kiritilgan bo'lsa — server sozlamalar bo'yicha o'zi aniqlaydi.
      const status = e.status !== undefined ? e.status : e.arrived_at ? null : r.status;
      if (!status && !arrived) continue;
      items.push({ student_id: id, status, arrived_at: arrived || null, note: (e.note ?? r.note ?? "") || null });
      snapshot[id] = e;
    }
    if (!items.length) { setSaveState(Object.keys(current).length ? "pending" : "idle"); return; }
    if (reasonRequired && !reasonRef.current.trim()) {
      setSaveState("pending");
      toast.error("Sabab kiriting", "Eski mashg'ulot davomatini o'zgartirish uchun sabab majburiy.");
      return;
    }

    const ids = items.map((i) => i.student_id);
    version.current++;
    setSaving(new Set(ids));
    setSaveState("saving");
    const r = await call<{ changed: number; notified: number }>("PUT", `sessions/${session.id}/attendance`, { items, reason: reasonRef.current.trim() || null });
    version.current++;
    setSaving(new Set());
    // Saqlash davomida yangi tahrir qilinmagan qatorlarnigina "toza" deb belgilaymiz.
    const dropSent = (e: Record<number, Edit>) => {
      const next = { ...e };
      for (const id of ids) if (next[id] === snapshot[id]) delete next[id];
      return next;
    };
    if (!r.ok) {
      // Belgilar saqlanmadi — tahrirlar qoladi ("Hozir saqlash" bilan qayta yuboriladi), yo'qolmaydi.
      setFailed(new Set(ids));
      setSaveState("error");
      toast.error("Davomat saqlanmadi", errorText(r.data));
      setTimeout(() => setFailed(new Set()), 2500);
      return;
    }
    setServer((d) => ({
      ...d,
      rows: d.rows.map((row) => {
        const it = items.find((i) => i.student_id === row.student_id);
        return it ? { ...row, status: it.status ?? row.status, note: it.note, arrived_at: it.arrived_at ? `${d.session.starts_at.slice(0, 10)} ${it.arrived_at}:00` : null } : row;
      }),
    }));
    setEdits(dropSent);
    setFailed(new Set());
    setSaveState("saved");
    if (r.data.notified) toast.info(`Ota-onalarga ${r.data.notified} ta xabar yuborildi`);
    void refreshNow(); // avtomatik aniqlangan holatlarni olish uchun
  }, [session.id, toast, refreshNow, reasonRequired]);

  const schedule = (ms = SAVE_DELAY) => {
    if (timer.current) clearTimeout(timer.current);
    setSaveState("pending");
    timer.current = setTimeout(() => void flush(), ms);
  };

  const set = (id: number, patch: Edit, delay?: number) => {
    setEdits((e) => ({ ...e, [id]: { ...e[id], ...patch } }));
    schedule(delay);
  };

  // Sahifadan chiqishda saqlanmagan o'zgarishlarni yo'qotmaslik.
  useEffect(() => {
    const onHide = () => { if (document.hidden && Object.keys(editsRef.current).length) void flush(); };
    const onUnload = (e: BeforeUnloadEvent) => { if (Object.keys(editsRef.current).length) { void flush(); e.preventDefault(); } };
    document.addEventListener("visibilitychange", onHide);
    window.addEventListener("beforeunload", onUnload);
    return () => { document.removeEventListener("visibilitychange", onHide); window.removeEventListener("beforeunload", onUnload); };
  }, [flush]);
  useEffect(() => () => { if (timer.current) clearTimeout(timer.current); }, []);

  function pick(r: Row, k: string) {
    const v = value(r);
    if (v.status === k) {
      // Saqlangan holatni o'chirib bo'lmaydi (API faqat almashtiradi); saqlanmaganini bekor qilish mumkin.
      if (r.status) return;
      set(r.student_id, { status: null });
      return;
    }
    set(r.student_id, { status: k });
  }

  function onSegKey(e: React.KeyboardEvent, r: Row) {
    if (!["ArrowRight", "ArrowLeft", "ArrowDown", "ArrowUp"].includes(e.key)) return;
    e.preventDefault();
    const cur = statusKeys.indexOf(value(r).status ?? "");
    const dir = e.key === "ArrowRight" || e.key === "ArrowDown" ? 1 : -1;
    const next = statusKeys[(cur + dir + statusKeys.length) % statusKeys.length];
    set(r.student_id, { status: next });
    (e.currentTarget.querySelector(`[data-s="${next}"]`) as HTMLElement | null)?.focus();
  }

  function markRest() {
    const present = statusKeys.includes("PRESENT") ? "PRESENT" : statusKeys[0];
    const patch: Record<number, Edit> = {};
    for (const r of rows) if (!value(r).status && !value(r).arrived) patch[r.student_id] = { ...edits[r.student_id], status: present };
    if (!Object.keys(patch).length) return toast.info("Hamma belgilangan");
    setEdits((e) => ({ ...e, ...patch }));
    schedule(200);
  }

  async function openHistory() {
    setHistory([]);
    const r = await call<{ data: HistoryItem[] }>("GET", `sessions/${session.id}/attendance/history`);
    if (!r.ok) { setHistory(null); return toast.error("Tarix yuklanmadi", errorText(r.data)); }
    setHistory(r.data.data);
  }

  async function saveTopic() {
    if (topic === (session.topic ?? "")) return;
    const r = await call("PATCH", `sessions/${session.id}`, { topic: topic || null });
    if (!r.ok) return toast.error("Mavzu saqlanmadi", errorText(r.data));
    setServer((d) => ({ ...d, session: { ...d.session, topic: topic || null } }));
    toast.success("Mavzu saqlandi");
  }

  async function toggleCancelled() {
    const cancel = !session.cancelled;
    if (cancel && !(await confirm({ title: "Mashg'ulot bekor qilinsinmi?", description: "Bekor qilingan mashg'ulot davomat va hisobotlarga kirmaydi.", confirmText: "Bekor qilish", cancelText: "Yo'q", tone: "danger" }))) return;
    const prev = server;
    version.current++;
    setServer((d) => ({ ...d, session: { ...d.session, cancelled: cancel } }));
    const r = await call("PATCH", `sessions/${session.id}`, { cancelled: cancel });
    if (!r.ok) { setServer(prev); return toast.error("Saqlanmadi", errorText(r.data)); }
    toast.success(cancel ? "Mashg'ulot bekor qilindi" : "Mashg'ulot tiklandi");
  }

  // ── Ko'rinish ─────────────────────────────────────────
  const counts = useMemo(() => {
    const c: Record<string, number> = {};
    for (const r of rows) {
      const v = edits[r.student_id]?.status !== undefined ? edits[r.student_id].status : r.status;
      if (v) c[v] = (c[v] ?? 0) + 1;
    }
    return c;
  }, [rows, edits]);
  const marked = Object.values(counts).reduce((a, b) => a + b, 0);
  const dirtyCount = Object.keys(edits).length;

  const stateView = {
    idle: null,
    pending: <span className={s.saveState}><CircleDot aria-hidden />Saqlanmagan o&apos;zgarishlar</span>,
    saving: <span className={s.saveState}><Loader2 className={s.spin} aria-hidden />Saqlanmoqda…</span>,
    saved: <span className={cx(s.saveState, s.ok)}><CheckCircle2 aria-hidden />Saqlandi</span>,
    error: <span className={cx(s.saveState, s.err)}><AlertCircle aria-hidden />Saqlanmadi</span>,
  }[saveState];

  return (
    <>
      <PageHeader
        back={{ href: `/dashboard/groups/${group.id}`, label: group.name }}
        title={`${fmtDate(session.starts_at, tz)} · ${fmtWeekday(session.starts_at, tz)}`}
        badge={session.cancelled ? <Badge tone="danger">Bekor qilingan</Badge> : !started ? <Badge tone="info">Hali boshlanmagan</Badge> : undefined}
        description={`${fmtTime(session.starts_at, tz)}–${fmtTime(session.ends_at, tz)} · ${rows.length} o'quvchi`}
        actions={
          <>
            <LiveStatus {...live} lastUpdated={live.lastUpdated ?? loadedAt} onRefresh={live.refresh} seconds={seconds} />
            {canMarkPerm && started && <Button variant="secondary" icon={<History />} onClick={openHistory}>Tarix</Button>}
            {canMarkPerm && (
              <Dropdown
                trigger={(p) => <Button variant="secondary" iconOnly aria-label="Mashg'ulot amallari" icon={<MoreHorizontal />} {...p} />}
                items={[session.cancelled
                  ? { label: "Mashg'ulotni tiklash", icon: <RotateCcw />, onSelect: toggleCancelled }
                  : { label: "Mashg'ulotni bekor qilish", icon: <Ban />, danger: true, onSelect: toggleCancelled }]}
              />
            )}
          </>
        }
      />

      {canMarkPerm && started && !session.cancelled && edit && !edit.can_edit && edit.message && (
        <Card style={{ marginBottom: 16 }}><EmptyState compact icon={<Lock />} title="Tahrirlash yopiq">{edit.message}</EmptyState></Card>
      )}
      {canMark && isPast && (
        <Card style={{ marginBottom: 16 }}>
          <CardBody>
            <Field
              label={reasonRequired ? "O'zgartirish sababi (majburiy)" : "O'zgartirish sababi (ixtiyoriy)"}
              hint="O'tgan mashg'ulotni tuzatayotganingiz uchun sabab tarixda saqlanadi."
            >
              <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Masalan: ma'lumotnoma keltirdi" />
            </Field>
            {edit?.deadline && <p className="subtle" style={{ margin: "8px 0 0" }}>Tahrirlash muddati: {fmtDateTime(edit.deadline, tz)} gacha.</p>}
          </CardBody>
        </Card>
      )}

      {canMark ? (
        <Card style={{ marginBottom: 16 }}>
          <CardBody>
            <div className={s.topic}>
              <Field label="Mashg'ulot mavzusi" hint="Maydondan chiqqanda saqlanadi">
                <Input value={topic} onChange={(e) => setTopic(e.target.value)} onBlur={saveTopic} onKeyDown={(e) => e.key === "Enter" && (e.currentTarget as HTMLInputElement).blur()} placeholder="Masalan: Present Simple" />
              </Field>
            </div>
          </CardBody>
        </Card>
      ) : session.topic ? <p className="muted">Mavzu: {session.topic}</p> : null}

      {rows.length === 0 ? (
        <Card><EmptyState icon={<Users />} title="Guruhda o'quvchi yo'q" /></Card>
      ) : (
        <>
          <div className={s.bar}>
            <div className={s.counts} aria-live="polite">
              <Badge tone="accent">{marked}/{rows.length} belgilangan</Badge>
              {statusKeys.map((k) => counts[k] ? <Badge key={k} tone={STATUS_TONE[k] ?? "neutral"}>{labels[k]}: {counts[k]}</Badge> : null)}
            </div>
            {stateView}
            {canMark && (
              <>
                {dirtyCount > 0 && saveState !== "saving" && <Button size="sm" onClick={() => void flush()}>Hozir saqlash</Button>}
                <Button size="sm" variant="soft" icon={<CheckCheck />} onClick={markRest}>Qolganlarni «{labels.PRESENT ?? "PRESENT"}»</Button>
              </>
            )}
            <div className={s.progress}><Progress value={rows.length ? (marked / rows.length) * 100 : 0} label="Belgilanganlar" /></div>
          </div>

          <Card>
            <ul className={s.list}>
              {rows.map((r) => {
                const v = value(r);
                const dirty = r.student_id in edits;
                return (
                  <li key={r.student_id} className={cx(s.row, !canMark && s.readonly, dirty && s.dirty, failed.has(r.student_id) && s.failed)}>
                    <div className={s.who}>
                      <span className={s.rowState} aria-hidden>
                        {saving.has(r.student_id) ? <Loader2 className={s.spin} /> : dirty ? <CircleDot /> : null}
                      </span>
                      <Avatar name={r.name} size={30} />
                      <strong>{r.name}</strong>
                    </div>
                    {canMark ? (
                      <>
                        <div className={s.seg} role="radiogroup" aria-label={`${r.name}: holat`} onKeyDown={(e) => onSegKey(e, r)}>
                          {statusKeys.map((k) => (
                            <button
                              key={k}
                              type="button"
                              role="radio"
                              data-s={k}
                              aria-checked={v.status === k}
                              tabIndex={v.status === k || (!v.status && k === statusKeys[0]) ? 0 : -1}
                              className={s.opt}
                              onClick={() => pick(r, k)}
                            >
                              {labels[k]}
                            </button>
                          ))}
                        </div>
                        <Input
                          type="time"
                          size="sm"
                          aria-label={`${r.name}: kelgan vaqti`}
                          title="Kelgan vaqti — holat tanlanmasa, sozlamalar bo'yicha avtomatik aniqlanadi"
                          value={v.arrived}
                          onChange={(e) => set(r.student_id, { arrived_at: e.target.value }, 1500)}
                        />
                        <Input
                          size="sm"
                          aria-label={`${r.name}: izoh`}
                          placeholder="Izoh"
                          value={v.note}
                          onChange={(e) => set(r.student_id, { note: e.target.value }, 1500)}
                        />
                      </>
                    ) : v.status ? <Badge tone={STATUS_TONE[v.status] ?? "neutral"} dot>{labels[v.status] ?? v.status}</Badge> : <span className="subtle">—</span>}
                  </li>
                );
              })}
            </ul>
          </Card>
        </>
      )}
      <Dialog open={history !== null} onClose={() => setHistory(null)} title="O'zgarishlar tarixi" icon={<History />} width={560}>
        {history && history.length === 0 ? (
          <EmptyState compact icon={<History />} title="O'zgarishlar yo'q" />
        ) : (
          <ul className={s.history}>
            {history?.map((h) => (
              <li key={h.id}>
                <div className={s.historyHead}><b>{h.by || "—"}</b><span className="subtle">{fmtDateTime(h.at, tz)}</span></div>
                {h.reason && <p className="subtle" style={{ margin: "2px 0 6px" }}>Sabab: {h.reason}</p>}
                <ul>
                  {h.changes.map((c, i) => (
                    <li key={i}>
                      {c.name}: {c.from ? <Badge tone={STATUS_TONE[c.from] ?? "neutral"}>{labels[c.from] ?? c.from}</Badge> : <Badge>—</Badge>} → <Badge tone={STATUS_TONE[c.to] ?? "neutral"}>{labels[c.to] ?? c.to}</Badge>
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        )}
      </Dialog>
      {!canMarkPerm ? null : !started && !session.cancelled ? (
        <p className="subtle" style={{ marginTop: 12 }}>Davomatni mashg&apos;ulot boshlangandan keyin belgilash mumkin.</p>
      ) : null}
    </>
  );
}
