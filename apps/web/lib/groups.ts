/** Guruh sahifasi uchun darslar oralig'i (o'tgan 120 kun, keyingi 60 kun) — ro'yxat yengil bo'lsin. */
export function sessionsPath(groupId: number, now = new Date()) {
  const day = (d: number) => new Date(now.getTime() + d * 86_400_000).toISOString().slice(0, 10);
  return `groups/${groupId}/sessions?from=${day(-120)}&to=${day(60)}`;
}
