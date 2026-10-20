<?php

namespace App\Support;

/**
 * Yagona telefon normalizatsiyasi: bot, login, foydalanuvchi yaratish/tahrirlash va qidiruv shu funksiyadan foydalanadi.
 *
 *   "90 123 45 67", "901234567", "8 90 123 45 67", "998901234567", "+998 (90) 123-45-67" → "+998901234567"
 *
 * O'zbekiston raqami bo'lmasa — auth.allow_foreign_phones yoqilgan bo'lsagina xalqaro (E.164) shaklda qabul qilinadi.
 */
final class Phone
{
    /** @return string|null "+998XXXXXXXXX" yoki null (raqam yaroqsiz). */
    public static function normalize(?string $raw, ?bool $allowForeign = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits = (string) preg_replace('/\D+/', '', $raw);
        if (str_starts_with($raw, '00')) {
            $digits = substr($digits, 2);
        }
        // Mahalliy yozuvlar: 9 raqam (90 123 45 67; "+" bilan ham — eski bot shunday saqlagan) yoki eski "8" prefiksi.
        if (strlen($digits) === 9) {
            $digits = '998'.$digits;
        } elseif (! $international && strlen($digits) === 10 && $digits[0] === '8') {
            $digits = '998'.substr($digits, 1);
        }
        if (preg_match('/^998\d{9}$/', $digits)) {
            return '+'.$digits;
        }
        $allowForeign ??= self::foreignAllowed();
        if ($allowForeign && $international && preg_match('/^[1-9]\d{7,14}$/', $digits) && ! str_starts_with($digits, '998')) {
            return '+'.$digits;
        }

        return null;
    }

    /** Qidiruv uchun: kamida 3 ta raqam bo'lsa — faqat raqamlar (masalan "90 123" → "90123"), aks holda null. */
    public static function searchDigits(?string $query): ?string
    {
        $query = trim((string) $query);
        if ($query === '' || preg_match('/\p{L}/u', $query)) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $query);

        return strlen($digits) >= 3 ? $digits : null;
    }

    private static function foreignAllowed(): bool
    {
        try {
            return app(Settings::class)->bool('auth.allow_foreign_phones');
        } catch (\Throwable) {
            return false;
        }
    }
}
