"use client";

import { useEffect, useState } from "react";
import { Select, usePrompt } from "@/components/ui";
import { call, errorText } from "@/lib/client";

type Room = { id: number; name: string; active: boolean };
const NEW = "__new";

/** Xona tanlash (xonalar ro'yxatidan). "Yangi xona…" — shu yerning o'zida qo'shish (group.manage). */
export default function RoomSelect({ value, onChange, current, id }: { value: string; onChange: (roomId: string) => void; current?: { id: number | null; name: string | null }; id?: string }) {
  const prompt = usePrompt();
  const [rooms, setRooms] = useState<Room[]>([]);
  useEffect(() => { call<{ data: Room[] }>("GET", "rooms").then((r) => r.ok && setRooms(r.data.data)); }, []);

  async function create() {
    let created: Room | null = null;
    await prompt({
      title: "Yangi xona",
      fields: [{ name: "name", label: "Xona nomi", required: true, placeholder: "Masalan: 101" }, { name: "capacity", label: "Sig'imi (o'rinlar)", type: "number" }],
      onSubmit: async (v) => {
        const r = await call<{ room: Room }>("POST", "rooms", { name: v.name.trim(), capacity: v.capacity ? Number(v.capacity) : null });
        if (!r.ok) return errorText(r.data);
        created = r.data.room;
      },
    });
    if (created) {
      const room: Room = created;
      setRooms((x) => [...x, room].sort((a, b) => a.name.localeCompare(b.name)));
      onChange(String(room.id));
    }
  }

  const missing = current?.id && !rooms.some((r) => r.id === current.id);
  return (
    <Select id={id} value={value} onChange={(e) => (e.target.value === NEW ? void create() : onChange(e.target.value))}>
      <option value="">Xonasiz</option>
      {missing && <option value={current!.id!}>{current!.name}</option>}
      {rooms.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
      <option value={NEW}>+ Yangi xona…</option>
    </Select>
  );
}
