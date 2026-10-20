"use client";

/** Mini App'dan API chaqiruvi: /api/tg proxy (token httpOnly cookie'da). 401 bo'lsa bir marta qayta kiradi. */
let reauth: (() => Promise<boolean>) | null = null;
let pending: Promise<boolean> | null = null;

export function setReauth(fn: () => Promise<boolean>) {
  reauth = fn;
}

/** Bir vaqtda kelgan bir nechta 401 — bitta qayta kirish. */
function reauthOnce(): Promise<boolean> {
  if (!reauth) return Promise.resolve(false);
  pending ??= reauth().finally(() => {
    pending = null;
  });
  return pending;
}

let offlineText = "Internet bilan aloqa yo'q.";

/** Matn sozlamadan (miniapp.texts.offline). */
export function setOfflineText(text: string) {
  offlineText = text;
}

export type ApiResult<T> = { ok: boolean; status: number; data: T };

export async function tg<T = any>(method: string, path: string, body?: unknown, retried = false): Promise<ApiResult<T>> {
  let res: Response;
  try {
    res = await fetch(`/api/tg/${path.replace(/^\//, "")}`, {
      method,
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "x-tisu-tg": "1" },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(20000),
    });
  } catch {
    return { ok: false, status: 0, data: { message: offlineText } as T };
  }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && !retried && (await reauthOnce())) return tg<T>(method, path, body, true);
  return { ok: res.ok, status: res.status, data };
}

export function errorText(data: any, fallback: string): string {
  if (data?.errors) return Object.values(data.errors).flat().join(" ");
  return data?.message || fallback;
}
