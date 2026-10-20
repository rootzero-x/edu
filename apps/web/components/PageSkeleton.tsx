import { Card, Skeleton } from "@/components/ui";

/** Yuklanish holati uchun umumiy skelet: sarlavha, statistika kartalari, ro'yxat. */
export default function PageSkeleton({ stats = 0, rows = 6, cards = 0 }: { stats?: number; rows?: number; cards?: number }) {
  return (
    <div aria-busy="true" aria-live="polite">
      <span className="sr-only">Yuklanmoqda…</span>
      <div style={{ marginBottom: 24, display: "flex", flexDirection: "column", gap: 10 }}>
        <Skeleton width={220} height={30} radius={8} />
        <Skeleton width={320} height={14} />
      </div>
      {stats > 0 && (
        <div style={{ display: "grid", gap: 16, gridTemplateColumns: "repeat(auto-fill, minmax(min(220px, 100%), 1fr))", marginBottom: 24 }}>
          {Array.from({ length: stats }, (_, i) => (
            <Card key={i} padded style={{ display: "flex", flexDirection: "column", gap: 14 }}>
              <Skeleton width="50%" height={12} />
              <Skeleton width="40%" height={30} radius={8} />
              <Skeleton height={6} radius={99} />
            </Card>
          ))}
        </div>
      )}
      {cards > 0 && (
        <div style={{ display: "grid", gap: 16, gridTemplateColumns: "repeat(auto-fill, minmax(min(280px, 100%), 1fr))", marginBottom: 24 }}>
          {Array.from({ length: cards }, (_, i) => (
            <Card key={i} style={{ overflow: "hidden" }}>
              <Skeleton height={120} radius={0} />
              <div style={{ padding: 20, display: "flex", flexDirection: "column", gap: 10 }}>
                <Skeleton width="70%" height={16} />
                <Skeleton width="90%" height={12} />
              </div>
            </Card>
          ))}
        </div>
      )}
      {rows > 0 && (
        <Card>
          {Array.from({ length: rows }, (_, i) => (
            <div key={i} style={{ display: "flex", alignItems: "center", gap: 12, padding: "14px 20px", borderBottom: i < rows - 1 ? "1px solid var(--border)" : 0 }}>
              <Skeleton width={32} height={32} radius={99} />
              <div style={{ flex: 1, display: "flex", flexDirection: "column", gap: 8 }}>
                <Skeleton width={`${40 + ((i * 17) % 35)}%`} height={12} />
                <Skeleton width={`${20 + ((i * 11) % 25)}%`} height={10} />
              </div>
              <Skeleton width={80} height={22} radius={99} />
            </div>
          ))}
        </Card>
      )}
    </div>
  );
}
