"use client";

import Link from "next/link";
import { useMemo, useState, type ReactNode } from "react";
import { Search, UsersRound } from "lucide-react";
import { Badge, Card, EmptyState, Input, Table, Tabs, Td, Th } from "@/components/ui";
import { personName, scheduleText } from "@/lib/format";
import type { GroupRow } from "@/lib/types";

export default function GroupsTable({ rows, empty }: { rows: GroupRow[]; empty?: ReactNode }) {
  const [q, setQ] = useState("");
  const [tab, setTab] = useState("active");
  const filtered = useMemo(() => {
    const needle = q.trim().toLowerCase();
    return rows.filter((g) => (tab === "all" || (tab === "active" ? g.active : !g.active))
      && (!needle || [g.name, g.course?.title, g.teacher && personName(g.teacher), g.branch?.name, g.room].some((v) => v?.toLowerCase().includes(needle))));
  }, [rows, q, tab]);

  if (rows.length === 0) {
    return <Card><EmptyState icon={<UsersRound />} title="Guruhlar yo'q" action={empty}>Guruh yaratilganda jadval bo&apos;yicha darslar avtomatik tuziladi.</EmptyState></Card>;
  }

  return (
    <>
      <div className="row" style={{ marginBottom: 16, alignItems: "flex-start" }}>
        <Tabs
          variant="pills"
          label="Holat"
          value={tab}
          onChange={setTab}
          items={[
            { id: "active", label: "Faol", count: rows.filter((g) => g.active).length },
            { id: "closed", label: "Yopiq", count: rows.filter((g) => !g.active).length },
            { id: "all", label: "Barchasi", count: rows.length },
          ]}
        />
        <div style={{ flex: "0 1 320px", minWidth: 220 }}>
          <Input type="search" placeholder="Qidirish…" aria-label="Guruhlarni qidirish" value={q} onChange={(e) => setQ(e.target.value)} leading={<Search />} />
        </div>
      </div>
      <Card>
        <Table caption="Guruhlar">
          <thead>
            <tr><Th>Guruh</Th><Th>Kurs</Th><Th>O&apos;qituvchi</Th><Th>Jadval</Th><Th numeric>O&apos;quvchilar</Th></tr>
          </thead>
          <tbody>
            {filtered.map((g) => (
              <tr key={g.id}>
                <Td>
                  <Link className="a" href={`/dashboard/groups/${g.id}`}>{g.name}</Link>
                  {!g.active && <Badge style={{ marginLeft: 8 }}>Yopiq</Badge>}
                  <div className="subtle" style={{ fontSize: "var(--text-xs)" }}>{[g.branch?.name, g.room].filter(Boolean).join(" · ")}</div>
                </Td>
                <Td>{g.course?.title}</Td>
                <Td>{g.teacher ? personName(g.teacher) : "—"}</Td>
                <Td style={{ whiteSpace: "nowrap" }}>{scheduleText(g.schedules)}</Td>
                <Td numeric>{g.students_count}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
        {filtered.length === 0 && <EmptyState compact icon={<Search />} title="Hech narsa topilmadi">Qidiruv so&apos;zini o&apos;zgartirib ko&apos;ring.</EmptyState>}
      </Card>
    </>
  );
}
