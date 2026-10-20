<?php

namespace App\Support;

/**
 * Sprint 2 (arizalar, import, qidiruv) telefonni faqat shu yerdan normallashtiradi.
 *
 * Sprint 1 dagi yagona App\Support\Phone main ga tushgach, normalize() va searchDigits() tanasini
 * bitta qator bilan almashtirish kifoya: `return Phone::normalize($raw);` / `return Phone::searchDigits($q);`.
 *
 *   "90 123 45 67", "901234567", "8 90 123 45 67", "998901234567", "+998 (90) 123-45-67" → "+998901234567"
 */
final class PhoneNormalizer
{
    /** @return string|null "+998XXXXXXXXX" yoki null (raqam yaroqsiz). */
    public static function normalize(?string $raw): ?string
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
        if (! $international) {
            if (strlen($digits) === 9) {
                $digits = '998'.$digits;
            } elseif (strlen($digits) === 10 && $digits[0] === '8') {
                $digits = '998'.substr($digits, 1);
            }
        }

        return preg_match('/^998\d{9}$/', $digits) ? '+'.$digits : null;
    }

    /** Qidiruv uchun: harf bo'lmasa va kamida 3 ta raqam bo'lsa — faqat raqamlar ("90 123" → "90123"), aks holda null. */
    public static function searchDigits(?string $query): ?string
    {
        $query = trim((string) $query);
        if ($query === '' || preg_match('/\p{L}/u', $query)) {
            return null;
        }
        $digits = (string) preg_replace('/\D+/', '', $query);

        return strlen($digits) >= 3 ? $digits : null;
    }
}
