"use client";

import { useState } from "react";
import { Award, ExternalLink, ShieldCheck } from "lucide-react";
import { Badge, Button, Card, CardHeader, EmptyState, Table, Td, Th, useConfirm, useToast } from "@/components/ui";
import { call, errorText } from "@/lib/client";
import RevokeCertificate from "@/components/RevokeCertificate";

type Row = { student_id: number; name: string; attendance_rate: number; marked_sessions: number; final_quiz_passed: boolean; certificate: string | null; blockers: string[] };

const REASONS: Record<string, string> = { NO_ATTENDANCE_DATA: "davomat belgilanmagan", LOW_ATTENDANCE: "davomat past", QUIZ_NOT_PASSED: "yakuniy test o'tilmagan" };

/** Guruh bo'yicha sertifikat shartlari va berish (shartlar sozlamalarda: certificate.*). */
export default function Certificates({ groupId }: { groupId: number }) {
  const toast = useToast();
  const confirm = useConfirm();
  const [rows, setRows] = useState<Row[] | null>(null);
  const [busy, setBusy] = useState(false);

  async function load() {
    setBusy(true);
    const r = await call<{ data: Row[] }>("GET", `groups/${groupId}/certificates`);
    setBusy(false);
    r.ok ? setRows(r.data.data) : toast.error("Yuklanmadi", errorText(r.data));
  }

  async function issue() {
    const ready = rows?.filter((r) => !r.certificate && r.blockers.length === 0).length ?? 0;
    if (!(await confirm({ title: "Sertifikat berilsinmi?", description: `${ready} ta o'quvchiga sertifikat beriladi va Telegram orqali xabar yuboriladi.`, confirmText: "Berish" }))) return;
    setBusy(true);
    const r = await call<{ issued: unknown[]; data: Row[] }>("POST", `groups/${groupId}/certificates`, {});
    setBusy(false);
    if (!r.ok) return toast.error("Berilmadi", errorText(r.data));
    setRows(r.data.data);
    toast.success(`${r.data.issued.length} ta sertifikat berildi`);
  }

  const ready = rows?.some((r) => !r.certificate && r.blockers.length === 0);
  return (
    <Card style={{ marginTop: 16 }}>
      <CardHeader
        title="Sertifikatlar"
        description="Davomat va yakuniy test shartlari sozlamalardan olinadi"
        actions={!rows
          ? <Button variant="secondary" icon={<ShieldCheck />} loading={busy} onClick={load}>Shartlarni tekshirish</Button>
          : <Button icon={<Award />} loading={busy} disabled={!ready} onClick={issue}>Sertifikat berish</Button>}
      />
      {rows && (rows.length === 0 ? <EmptyState compact icon={<Award />} title="Guruhda o'quvchi yo'q" /> : (
        <Table>
          <thead><tr><Th>O&apos;quvchi</Th><Th>Davomat</Th><Th>Yakuniy test</Th><Th>Holat</Th></tr></thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.student_id}>
                <Td>{r.name}</Td>
                <Td>{r.attendance_rate}% <span className="subtle">({r.marked_sessions} dars)</span></Td>
                <Td>{r.final_quiz_passed ? <Badge tone="success">O&apos;tgan</Badge> : <Badge>—</Badge>}</Td>
                <Td>
                  {r.certificate
                    ? <span style={{ display: "inline-flex", alignItems: "center", gap: 4 }}><a className="a" href={`/c/${r.certificate}`} target="_blank" rel="noreferrer">{r.certificate} <ExternalLink size={14} aria-hidden /></a><RevokeCertificate code={r.certificate} onDone={load} /></span>
                    : r.blockers.length
                      ? <span className="subtle">{r.blockers.map((b) => REASONS[b] ?? b).join(", ")}</span>
                      : <Badge tone="success">Berish mumkin</Badge>}
                </Td>
              </tr>
            ))}
          </tbody>
        </Table>
      ))}
    </Card>
  );
}
