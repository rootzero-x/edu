"use client";

import { useEffect, useState } from "react";
import { DoorOpen, Pencil, Plus, Trash2 } from "lucide-react";
import { Badge, Button, Dialog, EmptyState, Field, Input, Switch, useConfirm, usePrompt, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";
import s from "./calendar.module.css";

export type Room = { id: number; name: string; capacity: number | null; note: string | null; active: boolean; groups_count: number };

/** Xonalar ro'yxati (group.manage): qo'shish, nomini o'zgartirish, nofaol qilish, o'chirish. */
export default function RoomsDialog({ open, onClose, onChanged }: { open: boolean; onClose: () => void; onChanged?: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const prompt = usePrompt();
  const [rooms, setRooms] = useState<Room[] | null>(null);
  const [name, setName] = useState("");
  const [capacity, setCapacity] = useState("");
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const r = await call<{ data: Room[] }>("GET", "rooms?all=1");
    if (r.ok) setRooms(r.data.data);
  };
  useEffect(() => { if (open) void load(); }, [open]);

  async function add() {
    if (!name.trim()) return;
    setBusy(true);
    const r = await call("POST", "rooms", { name: name.trim(), capacity: capacity ? Number(capacity) : null });
    setBusy(false);
    if (!r.ok) return toast.error("Qo'shilmadi", errorText(r.data));
    setName(""); setCapacity("");
    await load();
    onChanged?.();
  }

  async function edit(room: Room) {
    await prompt({
      title: "Xonani tahrirlash",
      fields: [
        { name: "name", label: "Nomi", defaultValue: room.name, required: true },
        { name: "capacity", label: "Sig'imi (o'rinlar)", type: "number", defaultValue: room.capacity ? String(room.capacity) : "" },
        { name: "note", label: "Izoh", defaultValue: room.note ?? "" },
      ],
      onSubmit: async (v) => {
        const r = await call("PATCH", `rooms/${room.id}`, { name: v.name.trim(), capacity: v.capacity ? Number(v.capacity) : null, note: v.note || null });
        if (!r.ok) return errorText(r.data);
        await load();
        onChanged?.();
      },
    });
  }

  async function toggle(room: Room) {
    const r = await call("PATCH", `rooms/${room.id}`, { active: !room.active });
    if (!r.ok) return toast.error("Saqlanmadi", errorText(r.data));
    await load();
  }

  async function remove(room: Room) {
    if (!(await confirm({ title: "Xona o'chirilsinmi?", description: room.name, confirmText: "O'chirish", tone: "danger" }))) return;
    const r = await call("DELETE", `rooms/${room.id}`);
    if (!r.ok) return toast.error("O'chirilmadi", errorText(r.data));
    await load();
    onChanged?.();
  }

  return (
    <Dialog open={open} onClose={onClose} title="Xonalar" icon={<DoorOpen />} width={560}
      description="Guruh jadvali tuzilganda bir xonada bir vaqtda ikki guruh bo'lishiga yo'l qo'yilmaydi.">
      <form className={s.roomForm} onSubmit={(e) => { e.preventDefault(); void add(); }}>
        <Field label="Yangi xona"><Input value={name} onChange={(e) => setName(e.target.value)} placeholder="Masalan: 101" maxLength={60} /></Field>
        <Field label="Sig'imi"><Input type="number" min={1} max={1000} value={capacity} onChange={(e) => setCapacity(e.target.value)} /></Field>
        <Button type="submit" icon={<Plus />} loading={busy} disabled={!name.trim()}>Qo&apos;shish</Button>
      </form>
      {rooms === null ? <p className="subtle">Yuklanmoqda…</p> : rooms.length === 0 ? (
        <EmptyState compact icon={<DoorOpen />} title="Xonalar yo'q">Birinchi xonani qo&apos;shing.</EmptyState>
      ) : (
        <ul className={s.roomList}>
          {rooms.map((r) => (
            <li key={r.id} className={r.active ? undefined : s.inactive}>
              <span className={s.roomName}>
                <b>{r.name}</b>
                <span className="subtle">{[r.capacity ? `${r.capacity} o'rin` : null, `${r.groups_count} ta faol guruh`, r.note].filter(Boolean).join(" · ")}</span>
              </span>
              {!r.active && <Badge>Nofaol</Badge>}
              <Switch checked={r.active} onChange={() => toggle(r)} aria-label={`${r.name} — faol`} />
              <Button size="sm" variant="ghost" iconOnly aria-label={`${r.name} — tahrirlash`} icon={<Pencil />} onClick={() => edit(r)} />
              <Button size="sm" variant="dangerGhost" iconOnly aria-label={`${r.name} — o'chirish`} icon={<Trash2 />} onClick={() => remove(r)} />
            </li>
          ))}
        </ul>
      )}
    </Dialog>
  );
}
