"use client";

import { useEffect, useRef, useState } from "react";
import { Check, Search } from "lucide-react";
import { Avatar, EmptyState, Input } from "@/components/ui";
import { call } from "@/lib/client";
import { personName } from "@/lib/format";
import type { Person } from "@/lib/types";
import s from "./PersonPicker.module.css";

/**
 * Foydalanuvchini qidirib tanlash (ism yoki telefonning istalgan qismi bo'yicha) — aniq raqamni yozish shart emas.
 * `exclude` — allaqachon bog'langanlar (belgi bilan ko'rsatiladi, tanlab bo'lmaydi).
 */
export default function PersonPicker({ role, onPick, exclude, placeholder = "Ism yoki telefon: Aziz, 90 123", label = "Qidirish", empty }: {
  role: string; onPick: (p: Person) => void; exclude?: Set<number>; placeholder?: string; label?: string; empty?: React.ReactNode;
}) {
  const [q, setQ] = useState("");
  const [results, setResults] = useState<Person[] | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (timer.current) clearTimeout(timer.current);
    const ctrl = new AbortController();
    timer.current = setTimeout(async () => {
      const r = await call<{ data: Person[] }>("GET", `users?role=${role}&per_page=20${q.trim() ? `&q=${encodeURIComponent(q.trim())}` : ""}`, undefined, { signal: ctrl.signal }).catch(() => null);
      if (r?.ok) setResults(r.data.data ?? []);
    }, 250);
    return () => { ctrl.abort(); if (timer.current) clearTimeout(timer.current); };
  }, [q, role]);

  return (
    <div className={s.wrap}>
      <Input type="search" autoFocus placeholder={placeholder} value={q} onChange={(e) => setQ(e.target.value)} leading={<Search />} aria-label={label} />
      {results === null ? <p className="subtle" style={{ margin: 0 }}>Yuklanmoqda…</p> : results.length === 0 ? (
        <EmptyState compact icon={<Search />} title="Topilmadi">{empty}</EmptyState>
      ) : (
        <ul className={s.list} aria-label="Natijalar">
          {results.map((p) => {
            const linked = exclude?.has(p.id);
            return (
              <li key={p.id}>
                <button type="button" disabled={linked} onClick={() => onPick(p)}>
                  <Avatar name={personName(p)} />
                  <span className={s.text}><strong>{personName(p)}</strong><span className="subtle">{p.phone}</span></span>
                  {linked && <Check size={18} aria-label="Bog'langan" />}
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
