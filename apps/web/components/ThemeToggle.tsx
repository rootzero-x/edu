"use client";

import { useEffect, useState } from "react";
import { Monitor, Moon, Sun } from "lucide-react";
import { Button, Dropdown } from "@/components/ui";

type Mode = "light" | "dark" | "system";
const KEY = "tisu-theme";

/** <head> ichida, chizilishdan oldin ishlaydi — tema "miltillamasligi" uchun. */
export const themeScript = `try{var t=localStorage.getItem("${KEY}");if(t==="light"||t==="dark")document.documentElement.dataset.theme=t}catch(e){}`;

function apply(mode: Mode) {
  const el = document.documentElement;
  if (mode === "system") delete el.dataset.theme;
  else el.dataset.theme = mode;
  try { mode === "system" ? localStorage.removeItem(KEY) : localStorage.setItem(KEY, mode); } catch { /* maxfiy rejim */ }
}

export default function ThemeToggle() {
  const [mode, setMode] = useState<Mode>("system");
  useEffect(() => {
    const t = document.documentElement.dataset.theme;
    setMode(t === "light" || t === "dark" ? t : "system");
  }, []);
  const set = (m: Mode) => { apply(m); setMode(m); };
  const Icon = mode === "dark" ? Moon : mode === "light" ? Sun : Monitor;
  return (
    <Dropdown
      trigger={(p) => <Button variant="ghost" iconOnly aria-label="Mavzu: yorug' yoki qorong'i" icon={<Icon />} {...p} />}
      items={[
        { label: "Yorug'", icon: <Sun />, onSelect: () => set("light") },
        { label: "Qorong'i", icon: <Moon />, onSelect: () => set("dark") },
        { label: "Tizim bo'yicha", icon: <Monitor />, onSelect: () => set("system") },
      ]}
    />
  );
}
