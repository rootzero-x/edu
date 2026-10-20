"use client";

import { useId, useRef } from "react";
import { Paperclip } from "lucide-react";
import { Button, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { bool, list, num } from "@/lib/settings";
import { precheck } from "@/lib/upload";
import s from "./files.module.css";

/**
 * Fayl tanlash tugmasi. Ruxsat etilgan turlar va hajm sozlamalardan (uploads.*) olinadi;
 * brauzerda oldindan tekshiriladi, asosiy tekshiruv — serverda (fayl ichidan).
 */
export default function FilePicker({ onPick, label = "Fayl biriktirish", multiple = true, disabled, remaining, hideHint, icon, variant = "secondary", size = "sm", loading }: {
  onPick: (files: File[]) => void; label?: string; multiple?: boolean; disabled?: boolean; remaining?: number;
  hideHint?: boolean; icon?: React.ReactNode; variant?: "secondary" | "primary" | "soft"; size?: "sm" | "md"; loading?: boolean;
}) {
  const st = useSiteSettings();
  const toast = useToast();
  const ref = useRef<HTMLInputElement>(null);
  const id = useId();
  const allowed = list<string>(st, "uploads.allowed_types").map((x) => String(x).toLowerCase());
  const maxMb = num(st, "uploads.max_mb", 10);
  if (!bool(st, "uploads.enabled", true)) return null;

  function change(e: React.ChangeEvent<HTMLInputElement>) {
    const picked = Array.from(e.target.files ?? []);
    e.target.value = "";
    const ok: File[] = [];
    for (const f of picked) {
      const err = precheck(f, maxMb, allowed);
      if (err) toast.error(f.name, err);
      else ok.push(f);
    }
    const limited = remaining != null ? ok.slice(0, Math.max(0, remaining)) : ok;
    if (limited.length < ok.length) toast.info("Fayllar soni chegaralangan", `Yana ${Math.max(0, remaining ?? 0)} ta fayl biriktirish mumkin.`);
    if (limited.length) onPick(limited);
  }

  return (
    <div className={s.picker}>
      <input ref={ref} id={id} type="file" multiple={multiple} accept={allowed.map((x) => `.${x}`).join(",")} onChange={change} tabIndex={-1} aria-hidden />
      <Button variant={variant} size={size} icon={icon ?? <Paperclip />} loading={loading} disabled={disabled || remaining === 0} onClick={() => ref.current?.click()}
        title={`${allowed.join(", ")} · ${maxMb} MB gacha`}>{label}</Button>
      {!hideHint && <span className={s.hint}>{allowed.length ? `${allowed.slice(0, 8).join(", ")}${allowed.length > 8 ? "…" : ""} · ` : ""}{maxMb} MB gacha</span>}
    </div>
  );
}
