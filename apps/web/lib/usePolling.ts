"use client";

import { useCallback, useEffect, useRef, useState } from "react";

type Options = {
  /** Oraliq (soniya). 0 yoki manfiy — avtomatik yangilanish o'chiq. */
  seconds: number;
  enabled?: boolean;
  /** Darhol (va fetcher o'zgarganda — masalan filtr) yuklash. Boshlang'ich ma'lumot serverdan kelmaganda kerak. */
  immediate?: boolean;
};

/**
 * Yengil "real vaqt": WebSocket o'rniga polling (backend shared hostingda).
 * - sahifa yashirin bo'lsa (visibilitychange) to'xtaydi, qaytganda darhol yangilaydi;
 * - oyna fokus olganda yoki internet qaytganda ham yangilaydi;
 * - xatoda oraliq ikki barobar oshadi (4x gacha), muvaffaqiyatda tiklanadi;
 * - so'rovlar ustma-ust tushmaydi.
 */
export function usePolling<T>(fetcher: (signal: AbortSignal) => Promise<T | null>, onData: (data: T) => void, { seconds, enabled = true, immediate = false }: Options) {
  const [lastUpdated, setLastUpdated] = useState<number | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [failed, setFailed] = useState(false);
  const fetchRef = useRef(fetcher);
  const dataRef = useRef(onData);
  fetchRef.current = fetcher;
  dataRef.current = onData;
  const inflight = useRef<AbortController | null>(null);
  const backoff = useRef(1);
  const lastRun = useRef(0);

  const refresh = useCallback(async () => {
    if (inflight.current) return;
    lastRun.current = Date.now();
    const ctrl = new AbortController();
    inflight.current = ctrl;
    setRefreshing(true);
    try {
      const data = await fetchRef.current(ctrl.signal);
      if (ctrl.signal.aborted) return;
      if (data !== null) {
        dataRef.current(data);
        setLastUpdated(Date.now());
        setFailed(false);
        backoff.current = 1;
      } else if (data === null) {
        setFailed(true);
        backoff.current = Math.min(backoff.current * 2, 4);
      }
    } catch (e) {
      if ((e as Error)?.name !== "AbortError") {
        setFailed(true);
        backoff.current = Math.min(backoff.current * 2, 4);
      }
    } finally {
      if (inflight.current === ctrl) {
        inflight.current = null;
        setRefreshing(false);
      }
    }
  }, []);

  // Filtr o'zgarsa eski so'rov bekor qilinib, yangisi darhol yuboriladi.
  useEffect(() => {
    if (!immediate || !enabled) return;
    inflight.current?.abort();
    inflight.current = null;
    void refresh();
  }, [immediate, enabled, fetcher, refresh]);

  useEffect(() => {
    if (!enabled || seconds <= 0) return;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let stopped = false;

    const schedule = () => {
      if (timer) clearTimeout(timer);
      if (stopped || document.hidden) return;
      timer = setTimeout(async () => {
        await refresh();
        schedule();
      }, seconds * 1000 * backoff.current);
    };
    const wake = () => {
      if (document.hidden) {
        if (timer) clearTimeout(timer);
        timer = null;
        return;
      }
      // focus va visibilitychange ketma-ket kelganda ikki marta so'ramaslik uchun.
      if (Date.now() - lastRun.current < 2000) return schedule();
      void refresh().then(schedule);
    };

    schedule();
    document.addEventListener("visibilitychange", wake);
    window.addEventListener("focus", wake);
    window.addEventListener("online", wake);
    return () => {
      stopped = true;
      if (timer) clearTimeout(timer);
      inflight.current?.abort();
      document.removeEventListener("visibilitychange", wake);
      window.removeEventListener("focus", wake);
      window.removeEventListener("online", wake);
    };
  }, [enabled, seconds, refresh]);

  return { refresh, refreshing, lastUpdated, failed };
}
