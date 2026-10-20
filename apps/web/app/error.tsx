"use client";

import ErrorView from "@/components/ErrorView";

export default function RootError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main style={{ maxWidth: 560, paddingTop: 80 }}>
      <ErrorView error={error} reset={reset} homeHref="/" />
    </main>
  );
}
