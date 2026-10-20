import type { Metadata } from "next";
import { api, me } from "@/lib/api";
import type { GroupRow, Person } from "@/lib/types";
import { PageHeader } from "@/components/ui";
import GroupsTable from "./GroupsTable";
import NewGroup from "./NewGroup";

export const metadata: Metadata = { title: "Guruhlar" };

export default async function GroupsPage() {
  const user = await me();
  const manage = Boolean(user?.permissions.includes("group.manage"));
  const [{ data }, refs] = await Promise.all([
    api<{ data: GroupRow[] }>("/v1/groups"),
    manage
      ? Promise.all([
          api<{ data: { id: number; title: string }[] }>("/v1/courses"),
          api<{ data: { id: number; name: string; active: boolean }[] }>("/v1/branches"),
          api<{ data: Person[] }>("/v1/users?role=TEACHER&active=1&per_page=500"),
          api<{ data: Person[] }>("/v1/users?role=ADMIN"),
        ])
      : Promise.resolve(null),
  ]);
  const list = data.data ?? [];
  const newGroup = manage && refs && (
    <NewGroup
      courses={refs[0].data.data ?? []}
      branches={(refs[1].data.data ?? []).filter((b) => b.active)}
      teachers={[...(refs[2].data.data ?? []), ...(refs[3].data.data ?? [])]}
    />
  );
  return (
    <>
      <PageHeader title="Guruhlar" description={`${list.filter((g) => g.active).length} ta faol guruh`} actions={newGroup} />
      <GroupsTable rows={list} empty={newGroup} />
    </>
  );
}
