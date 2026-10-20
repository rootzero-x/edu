"use client";

import { useRouter } from "next/navigation";
import { Ban } from "lucide-react";
import { Button, useConfirm, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";

/** Sertifikatni bekor qilish (group.manage). Bekor qilingan kod ochiq tekshiruvda "topilmadi" bo'ladi. */
export default function RevokeCertificate({ code, onDone }: { code: string; onDone?: () => void }) {
  const confirm = useConfirm();
  const toast = useToast();
  const router = useRouter();
  return (
    <Button variant="ghost" size="sm" iconOnly aria-label={`${code} sertifikatini bekor qilish`} icon={<Ban />} onClick={async () => {
      if (!(await confirm({ title: "Sertifikat bekor qilinsinmi?", description: `${code} — tekshiruv sahifasida endi haqiqiy deb ko'rsatilmaydi.`, confirmText: "Bekor qilish", tone: "danger" }))) return;
      const r = await call("DELETE", `certificates/${encodeURIComponent(code)}`);
      if (!r.ok) return toast.error("Bajarilmadi", errorText(r.data));
      toast.success("Sertifikat bekor qilindi", code);
      onDone ? onDone() : router.refresh();
    }} />
  );
}
