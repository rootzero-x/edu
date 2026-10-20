"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";

export default function NewCourse() {
  const router = useRouter();
  const [title, setTitle] = useState("");
  const [fee, setFee] = useState("");
  const [err, setErr] = useState<string | null>(null);
  return (
    <form
      className="inline"
      onSubmit={async (e) => {
        e.preventDefault();
        const r = await call("POST", "courses", { title, monthly_fee: fee ? Number(fee) : 0 });
        if (!r.ok) return setErr(errorText(r.data));
        router.push(`/dashboard/courses/${r.data.course.id}`);
      }}
    >
      <input placeholder="Yangi kurs nomi" value={title} onChange={(e) => setTitle(e.target.value)} required />
      <input placeholder="Oylik to'lov" inputMode="numeric" value={fee} onChange={(e) => setFee(e.target.value.replace(/\D/g, ""))} />
      <button className="btn">Qo&apos;shish</button>
      {err && <span className="error">{err}</span>}
    </form>
  );
}
