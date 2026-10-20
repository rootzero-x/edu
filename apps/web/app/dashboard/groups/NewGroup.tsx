"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { call, errorText } from "@/lib/client";
import { WEEKDAYS, personName } from "@/lib/format";

type Slot = { weekday: number; start_time: string; end_time: string };

export default function NewGroup({ courses, branches, teachers }: { courses: any[]; branches: any[]; teachers: any[] }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [f, setF] = useState({ name: "", course_id: "", branch_id: "", teacher_id: "", start_date: new Date().toISOString().slice(0, 10), room: "" });
  const [slots, setSlots] = useState<Slot[]>([{ weekday: 1, start_time: "14:00", end_time: "15:30" }]);
  const [err, setErr] = useState<string | null>(null);

  if (!open) return <button className="btn" onClick={() => setOpen(true)}>+ Yangi guruh</button>;
  if (!branches.length) return <p className="error">Avval filial qo&apos;shing (Filiallar bo&apos;limi).</p>;

  return (
    <form className="card form" onSubmit={async (e) => {
      e.preventDefault();
      const r = await call("POST", "groups", {
        ...f, course_id: Number(f.course_id), branch_id: Number(f.branch_id), teacher_id: Number(f.teacher_id), room: f.room || null, schedules: slots,
      });
      if (!r.ok) return setErr(errorText(r.data));
      await call("POST", `groups/${r.data.group.id}/sessions/generate`, {});
      router.push(`/dashboard/groups/${r.data.group.id}`);
    }}>
      <div className="cols">
        <label>Nomi<input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></label>
        <label>Kurs<select value={f.course_id} onChange={(e) => setF({ ...f, course_id: e.target.value })} required>
          <option value="" />{courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}</select></label>
        <label>Filial<select value={f.branch_id} onChange={(e) => setF({ ...f, branch_id: e.target.value })} required>
          <option value="" />{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
        <label>O&apos;qituvchi<select value={f.teacher_id} onChange={(e) => setF({ ...f, teacher_id: e.target.value })} required>
          <option value="" />{teachers.map((t) => <option key={t.id} value={t.id}>{personName(t)}</option>)}</select></label>
        <label>Boshlanish sanasi<input type="date" value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} required /></label>
        <label>Xona<input value={f.room} onChange={(e) => setF({ ...f, room: e.target.value })} /></label>
      </div>
      <p className="muted">Haftalik jadval</p>
      {slots.map((s, i) => (
        <div className="inline" key={i}>
          <select value={s.weekday} onChange={(e) => setSlots(slots.map((x, j) => (j === i ? { ...x, weekday: Number(e.target.value) } : x)))}>
            {WEEKDAYS.slice(1).map((w, k) => <option key={k} value={k + 1}>{w}</option>)}
          </select>
          <input type="time" value={s.start_time} onChange={(e) => setSlots(slots.map((x, j) => (j === i ? { ...x, start_time: e.target.value } : x)))} />
          <input type="time" value={s.end_time} onChange={(e) => setSlots(slots.map((x, j) => (j === i ? { ...x, end_time: e.target.value } : x)))} />
          <button type="button" className="link danger" onClick={() => setSlots(slots.filter((_, j) => j !== i))}>×</button>
        </div>
      ))}
      <button type="button" className="link" onClick={() => setSlots([...slots, { ...slots[slots.length - 1] ?? { weekday: 1, start_time: "14:00", end_time: "15:30" } }])}>+ kun</button>
      <div className="toolbar">
        <button className="btn">Yaratish</button>
        <button type="button" className="btn ghost" onClick={() => setOpen(false)}>Bekor</button>
      </div>
      {err && <p className="error">{err}</p>}
    </form>
  );
}
