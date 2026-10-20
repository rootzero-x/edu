"use client";

import { useTg } from "../_lib/TgProvider";
import { date } from "../_lib/format";
import { openLink } from "../_lib/webapp";
import { Async, Empty, Page, Row, Section, useApi } from "../_ui/ui";

type Cert = { code: string; issued_at: string; student_id: number; first_name: string; last_name: string | null; course: string; group: string };

export default function TgCertificates() {
  const { t, tz, settings, me } = useTg();
  const q = useApi<{ data: Cert[] }>("certificates");
  const base = String(settings["certificate.public_base_url"] ?? "").replace(/\/+$/, "");
  return (
    <Page title={t("nav_certificates")}>
      <Async q={q}>
        {(d) => d.data.length === 0 ? <Empty text={t("certificates_empty")} icon="🎓" /> : (
          <Section>
            {d.data.map((c) => (
              <Row key={c.code} icon="🎓" title={c.course}
                subtitle={[c.student_id !== me.id && [c.first_name, c.last_name].filter(Boolean).join(" "), `№ ${c.code}`, date(c.issued_at, tz)].filter(Boolean).join(" · ")}
                onClick={base ? () => openLink(`${base}/${c.code}`) : undefined} />
            ))}
          </Section>
        )}
      </Async>
    </Page>
  );
}
