"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";

/**
 * /auth/me 200 emas (401/403 dan tashqari) bo'lganda dashboard o'rniga: avtomatik qayta urinadi.
 * 429 — "juda ko'p so'rov" (limit tiklanishini kutamiz), boshqalari — server vaqtincha javob bermayapti.
 */
export default function ServerUnavailable({ status, siteName }: { status: number; siteName: string }) {
  const router = useRouter();
  const limited = status === 429;
  const [attempt, setAttempt] = useState(0);
  const delay = Math.min(60, (limited ? 15 : 5) * 2 ** Math.min(attempt, 3));
  const [left, setLeft] = useState(delay);

  useEffect(() => {
    setLeft(delay);
    const tick = setInterval(() => setLeft((s) => Math.max(0, s - 1)), 1000);
    const retry = setTimeout(() => { setAttempt((a) => a + 1); router.refresh(); }, delay * 1000);
    return () => { clearInterval(tick); clearTimeout(retry); };
  }, [attempt, delay, router]);

  return (
    <main className="narrow" style={{ padding: "64px 16px", textAlign: "center" }}>
      <h1>{siteName}</h1>
      <p className="muted" role="status">
        {limited
          ? "Juda ko'p so'rov yuborildi. Birozdan so'ng avtomatik qayta urinamiz."
          : "Server vaqtincha javob bermayapti. Avtomatik qayta urinamiz."}
        {" "}({left} soniya)
      </p>
      <p><a className="a" href="">Hozir qayta yuklash</a></p>
    </main>
  );
}
