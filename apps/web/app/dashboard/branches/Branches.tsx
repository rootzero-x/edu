"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Building2, Lock, MapPin, MoreHorizontal, Pencil, Phone, Plus, Unlock } from "lucide-react";
import { Badge, Button, Card, Dropdown, EmptyState, PageHeader, Table, Td, Th, type PromptField, usePrompt, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";

export type Branch = { id: number; name: string; address: string | null; phone: string | null; active: boolean };

const fields = (b?: Branch): PromptField[] => [
  { name: "name", label: "Nomi", required: true, defaultValue: b?.name },
  { name: "address", label: "Manzil", defaultValue: b?.address ?? "" },
  { name: "phone", label: "Telefon", type: "tel", defaultValue: b?.phone ?? "" },
];

export default function Branches({ rows: initial }: { rows: Branch[] }) {
  const router = useRouter();
  const toast = useToast();
  const prompt = usePrompt();
  const [rows, setRows] = useState(initial);
  useEffect(() => setRows(initial), [initial]);

  const add = () => prompt({
    title: "Yangi filial",
    fields: fields(),
    submitText: "Qo'shish",
    onSubmit: async (v) => {
      const r = await call("POST", "branches", { name: v.name, address: v.address || null, phone: v.phone || null });
      if (!r.ok) return errorText(r.data);
      toast.success("Filial qo'shildi", v.name);
      router.refresh();
    },
  });

  const edit = (b: Branch) => prompt({
    title: "Filialni tahrirlash",
    fields: fields(b),
    onSubmit: async (v) => {
      const r = await call("PATCH", `branches/${b.id}`, { name: v.name, address: v.address || null, phone: v.phone || null });
      if (!r.ok) return errorText(r.data);
      setRows((l) => l.map((x) => (x.id === b.id ? { ...x, name: v.name, address: v.address || null, phone: v.phone || null } : x)));
      toast.success("Saqlandi");
      router.refresh();
    },
  });

  async function toggle(b: Branch) {
    setRows((l) => l.map((x) => (x.id === b.id ? { ...x, active: !b.active } : x)));
    const r = await call("PATCH", `branches/${b.id}`, { active: !b.active });
    if (!r.ok) {
      setRows((l) => l.map((x) => (x.id === b.id ? { ...x, active: b.active } : x)));
      return toast.error("Saqlanmadi", errorText(r.data));
    }
    toast.success(b.active ? "Filial yopildi" : "Filial ochildi", b.name);
  }

  return (
    <>
      <PageHeader title="Filiallar" description={`${rows.filter((b) => b.active).length} ta faol filial`} actions={<Button icon={<Plus />} onClick={add}>Qo&apos;shish</Button>} />
      <Card>
        {rows.length === 0 ? (
          <EmptyState icon={<Building2 />} title="Filial yo'q" action={<Button icon={<Plus />} onClick={add}>Birinchi filial</Button>}>Guruh yaratish uchun kamida bitta filial kerak.</EmptyState>
        ) : (
          <Table stack caption="Filiallar">
            <thead><tr><Th>Nomi</Th><Th>Manzil</Th><Th>Telefon</Th><Th>Holat</Th><Th actions><span className="sr-only">Amallar</span></Th></tr></thead>
            <tbody>
              {rows.map((b) => (
                <tr key={b.id}>
                  <Td><strong style={{ fontWeight: 600 }}>{b.name}</strong></Td>
                  <Td label="Manzil">{b.address ? <span style={{ display: "inline-flex", gap: 6, alignItems: "center" }}><MapPin size={14} aria-hidden className="subtle" />{b.address}</span> : <span className="subtle">—</span>}</Td>
                  <Td label="Telefon">{b.phone ? <a className="a" href={`tel:${b.phone}`}><Phone size={14} aria-hidden /> {b.phone}</a> : <span className="subtle">—</span>}</Td>
                  <Td label="Holat">{b.active ? <Badge tone="success" dot>Ochiq</Badge> : <Badge dot>Yopiq</Badge>}</Td>
                  <Td actions>
                    <Dropdown
                      trigger={(p) => <Button size="sm" variant="ghost" iconOnly aria-label={`${b.name} — amallar`} icon={<MoreHorizontal />} {...p} />}
                      items={[
                        { label: "Tahrirlash", icon: <Pencil />, onSelect: () => edit(b) },
                        b.active ? { label: "Yopish", icon: <Lock />, danger: true, onSelect: () => toggle(b) } : { label: "Ochish", icon: <Unlock />, onSelect: () => toggle(b) },
                      ]}
                    />
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}
