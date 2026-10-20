"use client";

import { WEB_HEADER } from "./safe";

export type CallResult<T = any> = { ok: boolean; status: number; data: T };

/** Brauzerdan API chaqiruvi — /api/proxy orqali (token httpOnly cookie'da, JS unga tegmaydi). */
export async function call<T = any>(method: string, path: string, body?: unknown, init: { signal?: AbortSignal } = {}): Promise<CallResult<T>> {
  let res: Response;
  try {
    res = await fetch(`/api/proxy/${path.replace(/^\//, "")}`, {
      method,
      headers: { "Content-Type": "application/json", Accept: "application/json", [WEB_HEADER]: "1" },
      body: body === undefined ? undefined : JSON.stringify(body),
      cache: "no-store",
      signal: init.signal,
    });
  } catch (e) {
    if ((e as Error)?.name === "AbortError") throw e;
    return { ok: false, status: 0, data: { message: "Tarmoq bilan aloqa yo'q." } as T };
  }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) window.location.href = `/login?next=${encodeURIComponent(window.location.pathname)}`;
  return { ok: res.ok, status: res.status, data };
}

export function errorText(data: any): string {
  if (data?.errors) return Object.values(data.errors).flat().join(" ");
  return data?.message ?? "Xatolik yuz berdi.";
}
