"use client";

import { createContext, useContext, type ReactNode } from "react";
import type { Settings } from "@/lib/settings";
import { DialogsProvider, ToastProvider } from "@/components/ui";

const SiteCtx = createContext<Record<string, unknown>>({});

/** Ochiq sozlamalar (site.*, ui.*, attendance.status_labels …) brauzer komponentlari uchun. */
export function useSiteSettings(): Record<string, unknown> {
  return useContext(SiteCtx);
}

/** Ildiz provayderlar: sozlamalar, toast, modal dialoglar. */
export default function SiteProvider({ settings, children }: { settings: Settings; children: ReactNode }) {
  return (
    <SiteCtx.Provider value={settings ?? {}}>
      <ToastProvider>
        <DialogsProvider>{children}</DialogsProvider>
      </ToastProvider>
    </SiteCtx.Provider>
  );
}
