"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Download, FileText, MoreHorizontal, Pencil, Plus, Trash2, Upload } from "lucide-react";
import { Button, Dropdown, usePrompt, useConfirm, useToast } from "@/components/ui";
import FilePicker from "@/components/files/FilePicker";
import { call, errorText, openFile, upload } from "@/lib/client";
import { fmtSize } from "@/lib/upload";

type Material = {
  id: number; type: "VIDEO" | "FILE" | "LINK" | "TEXT"; title: string; url: string | null; content: string | null;
  file?: { id: number; original_name: string; mime: string; size: number } | null;
};

const TYPE_OPTIONS = [
  { value: "VIDEO", label: "Video (YouTube, Vimeo …)" }, { value: "FILE", label: "Fayl havolasi (Google Drive …)" },
  { value: "LINK", label: "Sayt havolasi" }, { value: "TEXT", label: "Matn" },
];

function useMaterialForm() {
  const prompt = usePrompt();
  const toast = useToast();
  const router = useRouter();
  return (opts: { title: string; m?: Material; method: "POST" | "PATCH"; path: string; ok: string }) => opts.m?.file ? prompt({
    // Yuklangan fayl: faqat nomini o'zgartirish (faylni almashtirish — o'chirib, qayta yuklash).
    title: opts.title,
    fields: [{ name: "title", label: "Nomi", required: true, defaultValue: opts.m.title }],
    submitText: "Saqlash",
    onSubmit: async (v) => {
      const r = await call("PATCH", opts.path, { title: v.title });
      if (!r.ok) return errorText(r.data);
      toast.success(opts.ok);
      router.refresh();
    },
  }) : prompt({
    title: opts.title,
    fields: [
      { name: "type", label: "Turi", type: "select", required: true, defaultValue: opts.m?.type ?? "LINK", options: TYPE_OPTIONS },
      { name: "title", label: "Nomi", required: true, defaultValue: opts.m?.title ?? "" },
      { name: "url", label: "Havola (URL)", type: "url", placeholder: "https://…", defaultValue: opts.m?.url ?? "", hint: "Video, fayl yoki havola uchun" },
      { name: "content", label: "Matn", type: "textarea", defaultValue: opts.m?.content ?? "", hint: "Faqat «Matn» turi uchun" },
    ],
    submitText: "Saqlash",
    onSubmit: async (v) => {
      const r = await call(opts.method, opts.path, {
        type: v.type, title: v.title, url: v.type === "TEXT" ? null : v.url || null, content: v.type === "TEXT" ? v.content : null,
      });
      if (!r.ok) return errorText(r.data);
      toast.success(opts.ok);
      router.refresh();
    },
  });
}

/** Dars sarlavhasidagi amallar (xodim uchun): matnni tahrirlash, material qo'shish. */
export function LessonActions({ lessonId, title, body }: { lessonId: number; title: string; body: string | null }) {
  const prompt = usePrompt();
  const toast = useToast();
  const router = useRouter();
  const materialForm = useMaterialForm();
  return (
    <>
      <Button variant="secondary" icon={<Pencil />} onClick={() => prompt({
        title: "Darsni tahrirlash",
        fields: [
          { name: "title", label: "Nomi", required: true, defaultValue: title },
          { name: "body", label: "Dars matni", type: "textarea", defaultValue: body ?? "", hint: "O'quvchilarga ko'rinadigan asosiy matn" },
        ],
        submitText: "Saqlash",
        onSubmit: async (v) => {
          const r = await call("PATCH", `lessons/${lessonId}`, { title: v.title, body: v.body || null });
          if (!r.ok) return errorText(r.data);
          toast.success("Dars saqlandi");
          router.refresh();
        },
      })}>Tahrirlash</Button>
      <UploadMaterial lessonId={lessonId} />
      <Button icon={<Plus />} onClick={() => materialForm({ title: "Material qo'shish", method: "POST", path: `lessons/${lessonId}/materials`, ok: "Material qo'shildi" })}>Material</Button>
    </>
  );
}

/** Faylni to'g'ridan-to'g'ri yuklash (PDF, Word, PowerPoint, rasm …) — material sifatida qo'shiladi. */
function UploadMaterial({ lessonId }: { lessonId: number }) {
  const toast = useToast();
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  async function send(files: File[]) {
    setBusy(true);
    let ok = 0;
    for (const f of files) {
      const r = await upload(`lessons/${lessonId}/materials/upload`, f);
      if (r.ok) ok++;
      else toast.error(f.name, errorText(r.data));
    }
    setBusy(false);
    if (ok) { toast.success(`${ok} ta fayl yuklandi`); router.refresh(); }
  }
  return <FilePicker onPick={send} label="Fayl yuklash" icon={<Upload />} hideHint size="md" loading={busy} />;
}

/** Yuklangan faylni ochish: qisqa muddatli imzolangan havola orqali (kursga ruxsati borlarga). */
export function OpenFile({ file }: { file: { id: number; original_name: string; size: number } }) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  return (
    <Button variant="secondary" icon={<Download />} loading={busy} onClick={async () => {
      setBusy(true);
      const err = await openFile(file.id);
      setBusy(false);
      if (err) toast.error("Fayl ochilmadi", err);
    }}>
      {file.original_name} <span className="subtle">({fmtSize(file.size)})</span>
    </Button>
  );
}

/** Har bir material uchun: tahrirlash / o'chirish. */
export function MaterialActions({ material }: { material: Material }) {
  const confirm = useConfirm();
  const toast = useToast();
  const router = useRouter();
  const materialForm = useMaterialForm();
  return (
    <Dropdown
      trigger={(p) => <Button variant="ghost" size="sm" iconOnly aria-label="Material amallari" icon={<MoreHorizontal />} {...p} />}
      items={[
        { label: "Tahrirlash", icon: <FileText />, onSelect: () => materialForm({ title: "Materialni tahrirlash", m: material, method: "PATCH", path: `materials/${material.id}`, ok: "Saqlandi" }) },
        { label: "O'chirish", icon: <Trash2 />, danger: true, onSelect: async () => {
          if (!(await confirm({ title: "Material o'chirilsinmi?", description: material.title, confirmText: "O'chirish", tone: "danger" }))) return;
          const r = await call("DELETE", `materials/${material.id}`);
          if (!r.ok) return toast.error("O'chirilmadi", errorText(r.data));
          toast.success("Material o'chirildi");
          router.refresh();
        } },
      ]}
    />
  );
}
