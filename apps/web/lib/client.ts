"use client";

import { WEB_HEADER } from "./safe";
import { sendWithTicket, type UploadTicket } from "./upload";

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

/**
 * Fayl yuklash (multipart): avval proksi orqali bir martalik chipta olinadi, keyin fayl bevosita API ga yuboriladi
 * (Vercel 4,5 MB dan katta so'rovni o'tkazmaydi). Maydonlar (masalan, title) FormData'ga qo'shiladi.
 */
export async function upload<T = any>(path: string, file: File, fields: Record<string, string> = {}): Promise<CallResult<T>> {
  const ticket = await call<UploadTicket>("POST", "uploads/ticket", { path: path.replace(/^\//, "") });
  if (!ticket.ok) return ticket as unknown as CallResult<T>;
  return sendWithTicket(ticket.data, file, fields);
}

/**
 * Yuklangan faylni ochish: API'dan qisqa muddatli imzolangan havola olinadi va yangi oynada ochiladi.
 * Oyna so'rovdan oldin ochiladi — aks holda brauzer uni "popup" deb bloklaydi.
 */
export async function openFile(fileId: number): Promise<string | null> {
  const w = window.open("about:blank", "_blank");
  const r = await call<{ url: string }>("GET", `files/${fileId}/link`);
  if (!r.ok) {
    w?.close();
    return errorText(r.data);
  }
  if (w) {
    w.opener = null;
    w.location.href = r.data.url;
  } else {
    window.location.href = r.data.url;
  }
  return null;
}

