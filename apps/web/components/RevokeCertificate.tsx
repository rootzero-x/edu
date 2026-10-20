"use client";

import { useRouter } from "next/navigation";
import { Ban } from "lucide-react";
import { Button, usePrompt, useToast } from "@/components/ui";
import { useSiteSettings } from "@/components/SiteProvider";
import { call, errorText } from "@/lib/client";

/**
 * Sertifikatni bekor qilish (group.manage). Yozuv o'chirilmaydi: ochiq tekshiruv sahifasi
 * "bekor qilingan" deb sana va sabab bilan ko'rsatadi; kerak bo'lsa yangi kod bilan qayta beriladi.
 */
export default function RevokeCertificate({ code, onDone }: { code: string; onDone?: () => void }) {
  const prompt = usePrompt();
  const toast = useToast();
  const router = useRouter();
  const st = useSiteSettings();
  const reasons = Array.isArray(st["certificate.revoke_reasons"]) ? (st["certificate.revoke_reasons"] as string[]) : [];
  return (
    <Button variant="ghost" size="sm" iconOnly aria-label={`${code} sertifikatini bekor qilish`} icon={<Ban />} onClick={() => prompt({
      title: "Sertifikat bekor qilinsinmi?",
      description: `${code} — tekshiruv sahifasida "bekor qilingan" deb ko'rsatiladi (sabab bilan). Yozuv o'chirilmaydi.`,
      fields: [reasons.length
        ? { name: "reason", label: "Sabab", type: "select", required: true, options: reasons.map((r) => ({ value: r, label: r })) }
        : { name: "reason", label: "Sabab", required: true, placeholder: "Masalan: xato bilan berilgan" }],
      submitText: "Bekor qilish",
      onSubmit: async (v) => {
        const r = await call("DELETE", `certificates/${encodeURIComponent(code)}`, { reason: v.reason });
        if (!r.ok) return errorText(r.data);
        toast.success("Sertifikat bekor qilindi", code);
        onDone ? onDone() : router.refresh();
      },
    })} />
  );
}
