"use client";

import { useRouter } from "next/navigation";
import { useEffect, useId, useMemo, useRef, useState } from "react";
import { Award, BookOpen, CornerDownLeft, GraduationCap, Inbox, LayoutGrid, Search, UserRound, UsersRound, type LucideIcon } from "lucide-react";
import { call } from "@/lib/client";
import s from "./CommandPalette.module.css";

/** Kichik harf va apostroflarsiz: "tolov" → "To'lovlar" ni topadi. */
const plain = (s: string) => s.toLowerCase().replace(/['ʻʼ’‘`]/g, "");

type Hit = { type: string; id: number | string; title: string; subtitle: string; href: string; active: boolean };
type Item = Hit & { key: string };

const TYPES: Record<string, { label: string; icon: LucideIcon }> = {
  page: { label: "Bo'limlar", icon: LayoutGrid },
  student: { label: "O'quvchilar", icon: GraduationCap },
  user: { label: "Foydalanuvchilar", icon: UserRound },
  group: { label: "Guruhlar", icon: UsersRound },
  course: { label: "Kurslar", icon: BookOpen },
  lead: { label: "Arizalar", icon: Inbox },
  certificate: { label: "Sertifikatlar", icon: Award },
};

/**
 * Umumiy qidiruv: Ctrl+K (Mac: ⌘K) yoki "/" bilan ochiladi. O'quvchi, guruh, kurs, ariza va bo'limlarni topadi;
 * ↑/↓ bilan tanlanadi, Enter — o'tish, Esc — yopish. Natijalar foydalanuvchi roliga qarab serverda cheklanadi.
 */
export default function CommandPalette({ nav }: { nav: { href: string; label: string }[] }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const [hits, setHits] = useState<Hit[]>([]);
  const [loading, setLoading] = useState(false);
  const [active, setActive] = useState(0);
  const dialog = useRef<HTMLDialogElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const listId = useId();
  const [mac, setMac] = useState(false);

  useEffect(() => setMac(/Mac|iPhone|iPad/.test(navigator.platform)), []);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof HTMLElement && (e.target.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName));
      if ((e.key.toLowerCase() === "k" && (e.ctrlKey || e.metaKey)) || (e.key === "/" && !typing && !open)) {
        e.preventDefault();
        setOpen(true);
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [open]);
  useEffect(() => {
    const d = dialog.current;
    if (!d) return;
    if (open && !d.open) { d.showModal(); setTimeout(() => input.current?.focus(), 0); }
    if (!open && d.open) d.close();
    if (!open) { setQ(""); setHits([]); setActive(0); }
  }, [open]);

  useEffect(() => {
    const term = q.trim();
    if (term.length < 2) { setHits([]); setLoading(false); return; }
    setLoading(true);
    const ctrl = new AbortController();
    const t = setTimeout(async () => {
      const r = await call<{ data: Hit[] }>("GET", `search?q=${encodeURIComponent(term)}`, undefined, { signal: ctrl.signal }).catch(() => null);
      if (r?.ok) { setHits(r.data.data ?? []); setActive(0); }
      setLoading(false);
    }, 200);
    return () => { ctrl.abort(); clearTimeout(t); };
  }, [q]);

  const items: Item[] = useMemo(() => {
    const term = plain(q.trim());
    const pages = nav.filter((n) => !term || plain(n.label).includes(term))
      .map((n) => ({ type: "page", id: n.href, title: n.label, subtitle: "", href: n.href, active: true, key: `p:${n.href}` }));
    // Natijalar turlar bo'yicha guruhlanadi (bo'limlar — oxirida, qidiruv bo'sh bo'lsa — faqat ular).
    const order = ["student", "user", "group", "course", "lead", "certificate"];
    const sorted = [...hits].sort((a, b) => order.indexOf(a.type) - order.indexOf(b.type)).map((h) => ({ ...h, key: `${h.type}:${h.id}` }));
    return [...sorted, ...pages.slice(0, term ? 4 : 12)];
  }, [hits, nav, q]);

  const go = (it: Item | undefined) => {
    if (!it) return;
    setOpen(false);
    router.push(it.href);
  };
  const onKey = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown") { e.preventDefault(); setActive((i) => Math.min(items.length - 1, i + 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive((i) => Math.max(0, i - 1)); }
    else if (e.key === "Enter") { e.preventDefault(); go(items[active]); }
  };
  useEffect(() => {
    document.getElementById(`${listId}-${active}`)?.scrollIntoView({ block: "nearest" });
  }, [active, listId]);

  let lastType = "";
  return (
    <>
      <button type="button" className={s.trigger} onClick={() => setOpen(true)} aria-label="Qidirish (Ctrl+K)" aria-keyshortcuts="Control+K Meta+K">
        <Search aria-hidden />
        <span className={s.triggerText}>Qidirish…</span>
        <kbd className={s.kbd}>{mac ? "⌘" : "Ctrl"} K</kbd>
      </button>
      <dialog ref={dialog} className={s.dialog} aria-label="Qidiruv" onCancel={(e) => { e.preventDefault(); setOpen(false); }}
        onClick={(e) => { if (e.target === dialog.current) setOpen(false); }}>
        {open && (
          <div className={s.panel}>
            <div className={s.inputRow}>
              <Search aria-hidden />
              <input
                ref={input}
                role="combobox"
                aria-expanded={items.length > 0}
                aria-controls={listId}
                aria-activedescendant={items[active] ? `${listId}-${active}` : undefined}
                aria-autocomplete="list"
                placeholder="O'quvchi, telefon, guruh, kurs, ariza…"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onKeyDown={onKey}
              />
              {loading && <span className={s.spin} aria-hidden />}
              <kbd className={s.kbd}>Esc</kbd>
            </div>
            <ul id={listId} role="listbox" className={s.list} aria-label="Natijalar">
              {items.length === 0 && q.trim().length >= 2 && !loading && <li className={s.empty} role="presentation">Hech narsa topilmadi</li>}
              {items.map((it, i) => {
                const meta = TYPES[it.type] ?? TYPES.page;
                const Icon = meta.icon;
                const header = it.type !== lastType ? meta.label : null;
                lastType = it.type;
                return (
                  <li key={it.key} role="presentation">
                    {header && <div className={s.group} aria-hidden>{header}</div>}
                    <div
                      id={`${listId}-${i}`}
                      role="option"
                      aria-selected={i === active}
                      className={s.option}
                      data-inactive={!it.active || undefined}
                      onMouseMove={() => setActive(i)}
                      onClick={() => go(it)}
                    >
                      <Icon aria-hidden />
                      <span className={s.text}><b>{it.title}</b>{it.subtitle && <span>{it.subtitle}</span>}</span>
                      {i === active && <CornerDownLeft aria-hidden className={s.enter} />}
                    </div>
                  </li>
                );
              })}
            </ul>
            <div className={s.foot}><span>↑↓ tanlash</span><span>Enter — ochish</span><span>Esc — yopish</span></div>
          </div>
        )}
      </dialog>
    </>
  );
}
