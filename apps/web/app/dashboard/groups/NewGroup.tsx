"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Plus, Trash2 } from "lucide-react";
import { Button, Dialog, Field, Input, Select, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";
import { WEEKDAYS, personName } from "@/lib/format";
import type { Person, Schedule } from "@/lib/types";

const DEFAULT_SLOT: Schedule = { weekday: 1, start_time: "14:00", end_time: "15:30" };

export default function NewGroup({ courses, teachers }: { courses: { id: number; title: string }[]; teachers: Person[] }) {
  const router = useRouter();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [f, setF] = useState({ name: "", course_id: "", teacher_id: "", start_date: new Date().toISOString().slice(0, 10), room: "" });
  const [slots, setSlots] = useState<Schedule[]>([DEFAULT_SLOT]);
  const [err, setErr] = useState<string | null>(null);
  const setSlot = (i: number, patch: Partial<Schedule>) => setSlots((l) => l.map((x, j) => (j === i ? { ...x, ...patch } : x)));

  async function submit() {
    setBusy(true);
    setErr(null);
    const r = await call("POST", "groups", {
      ...f, course_id: Number(f.course_id), teacher_id: Number(f.teacher_id), room: f.room || null, schedules: slots,
    });
    if (!r.ok) { setBusy(false); return setErr(errorText(r.data)); }
    const gen = await call("POST", `groups/${r.data.group.id}/sessions/generate`, {});
    toast.success("Guruh yaratildi", gen.ok ? `${gen.data.created} ta dars jadvalga qo'shildi` : undefined);
    setOpen(false);
    setBusy(false);
    router.refresh(); // sahifalar keshi yangilansin
    router.push(`/dashboard/groups/${r.data.group.id}`);
  }

  return (
    <>
      <Button icon={<Plus />} onClick={() => setOpen(true)}>Yangi guruh</Button>
      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        title="Yangi guruh"
        description="Haftalik jadval bo'yicha darslar avtomatik yaratiladi."
        width={640}
        onSubmit={submit}
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>Bekor qilish</Button>
            <Button type="submit" loading={busy}>Yaratish</Button>
          </>
        }
      >
        {(
          <>
            <div className="cols" style={{ gap: 16 }}>
              <Field label="Nomi" required><Input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} autoFocus /></Field>
              <Field label="Kurs" required>
                <Select value={f.course_id} onChange={(e) => setF({ ...f, course_id: e.target.value })}>
                  <option value="">Tanlang…</option>{courses.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
                </Select>
              </Field>
              <Field label="O'qituvchi" required>
                <Select value={f.teacher_id} onChange={(e) => setF({ ...f, teacher_id: e.target.value })}>
                  <option value="">Tanlang…</option>{teachers.map((t) => <option key={t.id} value={t.id}>{personName(t)}</option>)}
                </Select>
              </Field>
              <Field label="Boshlanish sanasi" required><Input type="date" value={f.start_date} onChange={(e) => setF({ ...f, start_date: e.target.value })} /></Field>
              <Field label="Xona"><Input value={f.room} onChange={(e) => setF({ ...f, room: e.target.value })} /></Field>
            </div>
            <fieldset style={{ border: 0, padding: 0, margin: 0, display: "flex", flexDirection: "column", gap: 8 }}>
              <legend style={{ fontWeight: 600, fontSize: "var(--text-sm)", marginBottom: 8 }}>Haftalik jadval</legend>
              {slots.map((x, i) => (
                <div key={i} style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr auto", gap: 8, alignItems: "center" }}>
                  <Select aria-label="Hafta kuni" value={x.weekday} onChange={(e) => setSlot(i, { weekday: Number(e.target.value) })}>
                    {WEEKDAYS.slice(1).map((w, k) => <option key={k} value={k + 1}>{w}</option>)}
                  </Select>
                  <Input aria-label="Boshlanish" type="time" value={x.start_time} onChange={(e) => setSlot(i, { start_time: e.target.value })} />
                  <Input aria-label="Tugash" type="time" value={x.end_time} onChange={(e) => setSlot(i, { end_time: e.target.value })} />
                  <Button variant="dangerGhost" iconOnly aria-label="Kunni olib tashlash" icon={<Trash2 />} disabled={slots.length === 1} onClick={() => setSlots(slots.filter((_, j) => j !== i))} />
                </div>
              ))}
              <div>
                <Button variant="soft" size="sm" icon={<Plus />} onClick={() => setSlots([...slots, { ...(slots[slots.length - 1] ?? DEFAULT_SLOT), weekday: Math.min(7, (slots[slots.length - 1]?.weekday ?? 0) + 2) }])}>Kun qo&apos;shish</Button>
              </div>
            </fieldset>
            {err && <p className="error" role="alert" style={{ margin: 0 }}>{err}</p>}
          </>
        )}
      </Dialog>
    </>
  );
}
