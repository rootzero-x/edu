"use client";

/** Mini App'dan API chaqiruvi: /api/tg proxy (token httpOnly cookie'da). 401 bo'lsa bir marta qayta kiradi. */
let reauth: (() => Promise<boolean>) | null = null;

export function setReauth(fn: () => Promise<boolean>) {
  reauth = fn;
}

export type ApiResult<T> = { ok: boolean; status: number; data: T };

/** Vaqtinchalik xatolar (deploy, tarmoq uzilishi): GET so'rov shu kutishlar bilan qayta yuboriladi. */
const RETRY_DELAYS_MS = [800, 1600, 3200];
const TRANSIENT = new Set([0, 500, 502, 503, 504]);

export async function tg<T = any>(method: string, path: string, body?: unknown, retried = false): Promise<ApiResult<T>> {
  let r = await once<T>(method, path, body);
  // Faqat o'qish so'rovlari takrorlanadi — yozish (davomat, test) ikki marta ketmasin.
  if (method === "GET") {
    for (const ms of RETRY_DELAYS_MS) {
      if (!TRANSIENT.has(r.status)) break;
      await new Promise((ok) => setTimeout(ok, ms));
      r = await once<T>(method, path, body);
    }
  }
  if (r.status === 401 && !retried && reauth && (await reauth())) return tg<T>(method, path, body, true);
  return r;
}

async function once<T>(method: string, path: string, body?: unknown): Promise<ApiResult<T>> {
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
    return { ok: false, status: 0, data: { message: "Internet bilan aloqa yo'q." } as T };
  }
  const data = await res.json().catch(() => ({}));
  return { ok: res.ok, status: res.status, data };
}

export function errorText(data: any, fallback: string): string {
  if (data?.errors) return Object.values(data.errors).flat().join(" ");
  return data?.message || fallback;
}

/** Fayl yuklash (multipart) — /api/tg-upload orqali (token Mini App cookie'sida). */
export async function tgUpload<T = any>(path: string, file: File): Promise<ApiResult<T>> {
  const form = new FormData();
  form.append("file", file);
  let res: Response;
  try {
    res = await fetch(`/api/tg-upload/${path.replace(/^\//, "")}`, {
      method: "POST", credentials: "same-origin", headers: { "x-tisu-tg": "1" }, body: form, signal: AbortSignal.timeout(120000),
    });
  } catch {
    return { ok: false, status: 0, data: { message: "Internet bilan aloqa yo'q." } as T };
  }
  const data = await res.json().catch(() => ({ message: res.status === 413 ? "Fayl juda katta." : "" }));
  return { ok: res.ok, status: res.status, data };
}

