"use client";

import { useState } from "react";
import { Download, FileText, Image as ImageIcon, Music, Trash2, Video } from "lucide-react";
import { Button, cx, useConfirm, useToast } from "@/components/ui";
import { call, errorText, openFile } from "@/lib/client";
import { fmtSize, type StoredFile } from "@/lib/upload";
import s from "./files.module.css";

function FileIcon({ mime }: { mime: string }) {
  if (mime.startsWith("image/")) return <ImageIcon />;
  if (mime.startsWith("audio/")) return <Music />;
  if (mime.startsWith("video/")) return <Video />;
  return <FileText />;
}

/** Yuklangan fayllar: ochish (imzolangan havola) va — ruxsat bo'lsa — o'chirish. */
export default function FileList({ files, canDelete, onDeleted, pending = [] }: {
  files: StoredFile[]; canDelete?: boolean; onDeleted?: (id: number) => void; pending?: File[];
}) {
  const toast = useToast();
  const confirm = useConfirm();
  const [busy, setBusy] = useState<number | null>(null);
  if (files.length === 0 && pending.length === 0) return null;

  async function open(f: StoredFile) {
    setBusy(f.id);
    const err = await openFile(f.id);
    setBusy(null);
    if (err) toast.error("Fayl ochilmadi", err);
  }

  async function remove(f: StoredFile) {
    if (!(await confirm({ title: "Fayl o'chirilsinmi?", description: f.name, confirmText: "O'chirish", tone: "danger" }))) return;
    const r = await call("DELETE", `files/${f.id}`);
    if (!r.ok) return toast.error("O'chirilmadi", errorText(r.data));
    onDeleted?.(f.id);
  }

  return (
    <ul className={s.list}>
      {files.map((f) => (
        <li key={f.id} className={s.item}>
          <span className={s.icon} aria-hidden><FileIcon mime={f.mime} /></span>
          <span className={s.text}><span className={s.name} title={f.name}>{f.name}</span><span className={s.meta}>{fmtSize(f.size)}</span></span>
          <span className={s.actions}>
            <Button size="sm" variant="soft" iconOnly aria-label={`${f.name} — ochish`} icon={<Download />} loading={busy === f.id} onClick={() => open(f)} />
            {canDelete && <Button size="sm" variant="dangerGhost" iconOnly aria-label={`${f.name} — o'chirish`} icon={<Trash2 />} onClick={() => remove(f)} />}
          </span>
        </li>
      ))}
      {pending.map((f, i) => (
        <li key={`p${i}`} className={cx(s.item, s.pending)}>
          <span className={s.icon} aria-hidden><FileIcon mime={f.type} /></span>
          <span className={s.text}><span className={s.name}>{f.name}</span><span className={s.meta}>{fmtSize(f.size)} · yuklanadi</span></span>
        </li>
      ))}
    </ul>
  );
}
