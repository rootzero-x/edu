import { cookies } from "next/headers";

export const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
export const TOKEN_COOKIE = "tisu_token";

export type PublicSettings = Record<string, unknown> & {
  "site.name": string;
  "site.tagline": string;
  "site.description": string;
  "site.primary_color": string;
  "site.logo_url": string;
  "site.features": { title: string; text: string }[];
  "site.contact_phone": string;
  "site.contact_telegram": string;
  "site.address": string;
  "telegram.bot_username": string;
};

export type Me = {
  id: number;
  phone: string;
  first_name: string;
  last_name: string | null;
  role: string;
  permissions: string[];
};

/** Server tomonda API chaqiruvi (token cookie'dan). */
export async function api<T>(path: string, init: RequestInit = {}): Promise<{ status: number; data: T }> {
  const token = (await cookies()).get(TOKEN_COOKIE)?.value;
  let res: Response;
  try {
    res = await fetch(`${API_URL}${path}`, {
      ...init,
      cache: "no-store",
      signal: init.signal ?? AbortSignal.timeout(15000),
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(init.headers ?? {}),
      },
    });
  } catch {
    return { status: 503, data: { message: "API bilan aloqa yo'q." } as T };
  }
  const data = (await res.json().catch(() => ({}))) as T;
  return { status: res.status, data };
}

/** Ochiq sozlamalar — sayt nomi, matnlar, rang. API ishlamasa minimal zaxira. */
export async function publicSettings(): Promise<PublicSettings | null> {
  try {
    const res = await fetch(`${API_URL}/v1/public/settings`, { next: { revalidate: 60 }, signal: AbortSignal.timeout(4000) });
    if (!res.ok) return null;
    return ((await res.json()) as { settings: PublicSettings }).settings;
  } catch {
    return null;
  }
}

export async function me(): Promise<Me | null> {
  const { status, data } = await api<{ user: Me }>("/v1/auth/me");
  return status === 200 ? data.user : null;
}

export const cookieOptions = {
  httpOnly: true,
  secure: process.env.NODE_ENV === "production",
  sameSite: "lax" as const,
  path: "/",
  maxAge: 60 * 60 * 24 * 30,
};

export type PublicCourse = {
  id: number;
  title: string;
  description: string | null;
  cover_url: string | null;
  monthly_fee: number;
  duration_months: number;
  modules_count: number;
};

/** Bosh sahifa uchun e'lon qilingan kurslar (soni site.courses_limit sozlamasidan). */
export async function publicCourses(): Promise<PublicCourse[]> {
  try {
    const res = await fetch(`${API_URL}/v1/public/courses`, { next: { revalidate: 60 }, signal: AbortSignal.timeout(4000) });
    if (!res.ok) return [];
    return ((await res.json()) as { data: PublicCourse[] }).data ?? [];
  } catch {
    return [];
  }
}
