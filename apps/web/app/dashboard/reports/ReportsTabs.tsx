"use client";

import { useEffect, useState } from "react";
import { BarChart3, Gauge } from "lucide-react";
import { Tabs } from "@/components/ui";
import ReportsView, { type Refs } from "./ReportsView";
import KpiView from "./KpiView";

/** Hisobotlar: "Ko'rsatkichlar" (oylik KPI) va "Davomat va testlar" (davr bo'yicha batafsil). Tanlov URL'da (#kpi). */
export default function ReportsTabs({ refs }: { refs: Refs }) {
  const [tab, setTab] = useState("kpi");
  useEffect(() => { if (window.location.hash === "#details") setTab("details"); }, []);
  const change = (id: string) => { setTab(id); history.replaceState(null, "", id === "kpi" ? location.pathname : "#details"); };
  const switcher = (
    <div style={{ marginBottom: "var(--space-4)" }}>
      <Tabs variant="pills" label="Hisobot turi" value={tab} onChange={change}
        items={[{ id: "kpi", label: "Ko'rsatkichlar", icon: <Gauge /> }, { id: "details", label: "Batafsil", icon: <BarChart3 /> }]} />
    </div>
  );
  return tab === "kpi" ? <KpiView refs={refs} switcher={switcher} /> : <ReportsView refs={refs} switcher={switcher} />;
}
