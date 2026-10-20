"use client";

/** Brauzerdan API chaqiruvi — /api/proxy orqali (token httpOnly cookie'da). */
export async function call<T = any>(method: string, path: string, body?: unknown): Promise<{ ok: boolean; status: number; data: T }> {
  const res = await fetch(`/api/proxy/${path.replace(/^\//, "")}`, {
    method,
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) window.location.href = "/login";
  return { ok: res.ok, status: res.status, data };
}

export function errorText(data: any): string {
  if (data?.errors) return Object.values(data.errors).flat().join(" ");
  return data?.message ?? "Xatolik.";
}
