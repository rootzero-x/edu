"use client";

import { useState } from "react";
import { AlertTriangle, Users as UsersIcon } from "lucide-react";
import { Card, EmptyState, PageHeader, Tabs } from "@/components/ui";
import ChildOverview from "@/components/family/ChildOverview";
import { useSiteSettings } from "@/components/SiteProvider";
import type { ChildOverview as Data } from "@/lib/family";
import { personName } from "@/lib/format";
import { obj } from "@/lib/settings";

/** Ota-ona: har bir farzand bo'yicha guruhlar, davomat, test natijalari va to'lov holati. */
export default function ChildrenView({ initial }: { initial: Data[] | null }) {
  const st = useSiteSettings();
  const t = obj<Record<string, string>>(st, "miniapp.texts");
  const [active, setActive] = useState(String(initial?.[0]?.id ?? ""));
  const title = t.my_children || "Farzandlarim";

  if (initial === null) return (<><PageHeader title={title} /><Card><EmptyState icon={<AlertTriangle />} title="Ma'lumotni yuklab bo'lmadi">Sahifani yangilab ko&apos;ring.</EmptyState></Card></>);
  if (initial.length === 0) return (<><PageHeader title={title} /><Card><EmptyState icon={<UsersIcon />} title={t.children_empty || "Farzand bog'lanmagan"}>Administratorga murojaat qiling — u farzandingizni akkauntingizga bog&apos;laydi.</EmptyState></Card></>);

  const child = initial.find((c) => String(c.id) === active) ?? initial[0];
  return (
    <>
      <PageHeader title={title} description={initial.length > 1 ? `${initial.length} nafar farzand` : personName(child)} />
      {initial.length > 1 && (
        <div style={{ marginBottom: 16 }}>
          <Tabs variant="pills" label="Farzandni tanlash" value={String(child.id)} onChange={setActive} items={initial.map((c) => ({ id: String(c.id), label: personName(c) }))} />
        </div>
      )}
      <ChildOverview data={child} />
    </>
  );
}
