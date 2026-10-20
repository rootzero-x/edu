import { Suspense } from "react";
import { publicSettings } from "@/lib/api";
import LoginForm from "./LoginForm";

export default async function LoginPage() {
  const s = await publicSettings();
  return (
    <main className="narrow">
      <h1>{s?.["site.name"] ?? "TISU Edu"}</h1>
      <p className="lead">Tizimga kirish</p>
      <Suspense>
        <LoginForm telegramEnabled={Boolean(s?.["telegram.bot_username"])} />
      </Suspense>
    </main>
  );
}
