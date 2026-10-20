"use client";

import { useEffect, useRef, useState, type ImgHTMLAttributes, type ReactNode } from "react";

/**
 * Tashqi URL'dan rasm (sozlamalar, kurs muqovasi, logo). Yuklanmasa — `fallback` ko'rsatiladi.
 * Server HTML'idagi rasm hydration'dan oldin xato berishi mumkin (onError o'tkazib yuboriladi),
 * shuning uchun mount'da ham tekshiramiz.
 */
export function SafeImage({ src, fallback = null, alt = "", ...rest }: Omit<ImgHTMLAttributes<HTMLImageElement>, "src"> & { src?: string | null; fallback?: ReactNode }) {
  const [failed, setFailed] = useState(false);
  const ref = useRef<HTMLImageElement>(null);
  useEffect(() => setFailed(false), [src]);
  useEffect(() => {
    const img = ref.current;
    if (img && img.complete && img.naturalWidth === 0) setFailed(true);
  }, [src]);
  if (!src || failed) return <>{fallback}</>;
  return <img ref={ref} src={src} alt={alt} onError={() => setFailed(true)} {...rest} />;
}
