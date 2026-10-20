"use client";

import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { Button, Dialog, Field, Input, Select, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";
import { WEEKDAYS_LONG, personName } from "@/lib/format";
import type { GroupDetail, Person, Schedule } from "@/lib/types";

/** Guruhni tahrirlash: nom, o'qituvchi, xona, tugash sanasi va haftalik jadval. */
export default function EditGroup({ open, onClose, group, onSaved }: { open: boolean; onClose: () => void; group: GroupDetail & { end_date?: string | null }; onSaved: () => void }) {
  const toast = useToast();
  const [teachers, setTeachers] = useState<Person[]>([]);
  const [busy, setBusy] = useState(false);
  const [f, setF] = useState({ name: "", teacher_id: "", room: "", end_date: "" });
  const [slots, setSlots] = useState<Schedule[]>([]);

  useEffect(() => {
    if (!open) return;
    setF({
      name: group.name,
      teacher_id: group.teacher ? String(group.teacher.id) : "",
      room: group.room ?? "",
      end_date: (group.end_date ?? "").slice(0, 10),
    });
    setSlots(group.schedules.map((x) => ({ weekday: x.weekday, start_time: x.start_time.slice(0, 5), end_time: x.end_time.slice(0, 5) })));
    call<{ data: Person[] }>("GET", "users?role=TEACHER&active=1&per_page=500").then((r) => r.ok && setTeachers(r.data.data ?? []));
  }, [open, group]);

  async function save() {
    setBusy(true);
    const r = await call("PATCH", `groups/${group.id}`, {
      name: f.name,
      ...(f.teacher_id ? { teacher_id: Number(f.teacher_id) } : {}),
      room: f.room || null,
      end_date: f.end_date || null,
      schedules: slots,
    });
    setBusy(false);
    if (!r.ok) return toast.error("Saqlanmadi", errorText(r.data));
    // Jadval o'zgargan bo'lsa kelgusi darslar yangi jadvaldan qo'shiladi.
    await call("POST", `groups/${group.id}/sessions/generate`, {});
    toast.success("Guruh yangilandi");
    onClose();
    onSaved();
  }

  const setSlot = (i: number, patch: Partial<Schedule>) => setSlots((xs) => xs.map((x, j) => (j === i ? { ...x, ...patch } : x)));

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title="Guruhni tahrirlash"
      icon={<Pencil />}
      width={560}
      onSubmit={save}
      footer={<><Button type="button" variant="secondary" onClick={onClose}>Bekor</Button><Button type="submit" loading={busy}>Saqlash</Button></>}
    >
      <Field label="Nomi" required><Input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} required /></Field>
      <Field label="O'qituvchi">
        <Select value={f.teacher_id} onChange={(e) => setF({ ...f, teacher_id: e.target.value })}>
          {!teachers.some((t) => String(t.id) === f.teacher_id) && group.teacher && <option value={group.teacher.id}>{personName(group.teacher)}</option>}
          {teachers.map((t) => <option key={t.id} value={t.id}>{personName(t)}</option>)}
        </Select>
      </Field>
      <Field label="Xona"><Input value={f.room} onChange={(e) => setF({ ...f, room: e.target.value })} /></Field>
      <Field label="Tugash sanasi" hint="Bo'sh — muddatsiz"><Input type="date" value={f.end_date} min={group.start_date?.slice(0, 10)} onChange={(e) => setF({ ...f, end_date: e.target.value })} /></Field>
      <Field label="Haftalik jadval" hint="O'zgarish faqat kelgusi yaratiladigan darslarga ta'sir qiladi">
        <div style={{ display: "grid", gap: 8 }}>
          {slots.map((x, i) => (
            <div key={i} style={{ display: "grid", gridTemplateColumns: "1.4fr 1fr 1fr auto", gap: 8, alignItems: "center" }}>
              <Select aria-label="Hafta kuni" value={x.weekday} onChange={(e) => setSlot(i, { weekday: Number(e.target.value) })}>
                {WEEKDAYS_LONG.slice(1).map((w, k) => <option key={k} value={k + 1}>{w}</option>)}
              </Select>
              <Input aria-label="Boshlanish" type="time" value={x.start_time} onChange={(e) => setSlot(i, { start_time: e.target.value })} />
              <Input aria-label="Tugash" type="time" value={x.end_time} onChange={(e) => setSlot(i, { end_time: e.target.value })} />
              <Button type="button" variant="ghost" iconOnly aria-label="O'chirish" icon={<Trash2 />} onClick={() => setSlots(slots.filter((_, j) => j !== i))} />
            </div>
          ))}
          <Button type="button" variant="secondary" size="sm" icon={<Plus />} onClick={() => setSlots([...slots, slots[slots.length - 1] ?? { weekday: 1, start_time: "14:00", end_time: "15:30" }])}>Kun qo&apos;shish</Button>
        </div>
      </Field>
    </Dialog>
  );
}
