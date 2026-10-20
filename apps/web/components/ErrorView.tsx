"use client";

import { useEffect } from "react";
import { AlertTriangle, Home, RotateCcw } from "lucide-react";
import { Button, ButtonLink, Card, EmptyState } from "@/components/ui";

/** error.tsx lar uchun umumiy ko'rinish. */
export default function ErrorView({ error, reset, homeHref = "/dashboard" }: { error: Error & { digest?: string }; reset: () => void; homeHref?: string }) {
  useEffect(() => { console.error(error); }, [error]);
  return (
    <Card>
      <EmptyState
        icon={<AlertTriangle />}
        title="Nimadir noto'g'ri ketdi"
        action={
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap", justifyContent: "center" }}>
            <Button icon={<RotateCcw />} onClick={reset}>Qayta urinish</Button>
            <ButtonLink href={homeHref} variant="secondary" icon={<Home />}>Bosh sahifa</ButtonLink>
          </div>
        }
      >
        Sahifani yuklashda xatolik yuz berdi. Internet aloqasini tekshirib, qayta urinib ko&apos;ring.
        {error.digest && <><br /><span className="mono subtle">#{error.digest}</span></>}
      </EmptyState>
    </Card>
  );
}
