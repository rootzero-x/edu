"use client";

import { useRef, useState } from "react";
import { tg } from "../_lib/api";
import { useTg } from "../_lib/TgProvider";
import { haptic, openLink } from "../_lib/webapp";
import { Row } from "./ui";
import { fmtSize, precheck, type StoredFile } from "@/lib/upload";

/** Faylni ochish: qisqa muddatli imzolangan havola olinadi va brauzerda ochiladi. */
export async function openStoredFile(id: number): Promise<string | null> {
  const r = await tg<{ url: string; message?: string }>("GET", `files/${id}/link`);
  if (!r.ok) return r.data?.message || "";
  openLink(r.data.url);
  return null;
}

export function FileRows({ files, onError }: { files: StoredFile[]; onError?: (msg: string) => void }) {
  const { t } = useTg();
  return (
    <>
      {files.map((f) => (
        <Row key={f.id} icon="📎" title={f.name} subtitle={fmtSize(f.size)}
          onClick={async () => { const e = await openStoredFile(f.id); if (e !== null) onError?.(e || t("error")); }} />
      ))}
    </>
  );
}

/** Fayl tanlash (Telegram ichida ham ishlaydi). Turlar va hajm — uploads.* sozlamalaridan. */
export function FileAttach({ onPick, disabled, staff }: { onPick: (f: File[]) => void; disabled?: boolean; staff?: boolean }) {
  const { t, settings } = useTg();
  const ref = useRef<HTMLInputElement>(null);
  const [err, setErr] = useState<string | null>(null);
  const allowed = (Array.isArray(settings["uploads.allowed_types"]) ? settings["uploads.allowed_types"] : []).map((x: unknown) => String(x).toLowerCase());
  const maxMb = typeof settings["uploads.max_mb"] === "number" ? settings["uploads.max_mb"] : 10;
  if (settings["uploads.enabled"] === false || (!staff && settings["uploads.student_files"] === false)) return null;
  return (
    <>
      <input ref={ref} type="file" multiple hidden accept={allowed.map((x: string) => `.${x}`).join(",")} onChange={(e) => {
        const picked = Array.from(e.target.files ?? []);
        e.target.value = "";
        const ok: File[] = [];
        let first: string | null = null;
        for (const f of picked) {
          const msg = precheck(f, maxMb, allowed);
          if (msg) first ??= `${f.name}: ${msg}`;
          else ok.push(f);
        }
        setErr(first);
        if (ok.length) { haptic.select(); onPick(ok); }
      }} />
      <button type="button" className="tg-btn ghost" disabled={disabled} onClick={() => ref.current?.click()}>{t("hw_attach")}</button>
      <p className="tg-hint" style={{ margin: "6px 2px 0", fontSize: ".78rem" }}>{allowed.join(", ")} · {maxMb} MB</p>
      {err && <p className="tg-hint" style={{ color: "var(--c-danger)", margin: "6px 2px 0" }}>{err}</p>}
    </>
  );
}
