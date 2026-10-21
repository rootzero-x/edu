<?php

namespace App\Support;

/**
 * Katta fayllarni to'g'ridan-to'g'ri API'ga yuklash uchun bir martalik "chipta" (Vercel proksi 4,5 MB dan katta so'rovni o'tkazmaydi).
 * Chipta — 10 daqiqalik Sanctum tokeni, faqat bitta yuklash yo'li uchun (ability "upload:<yo'l>") va ishlatilgach o'chiriladi.
 */
final class UploadTickets
{
    public const TOKEN_NAME = 'upload-ticket';

    /** Yuklash mumkin bo'lgan yo'llar (web lib/upload.ts UPLOAD_PATHS bilan bir xil). */
    public const PATHS = [
        '#^lessons/\d+/materials/upload$#',
        '#^assignments/\d+/files$#',
        '#^assignments/\d+/submission/files$#',
    ];

    public static function allowed(string $path): bool
    {
        foreach (self::PATHS as $re) {
            if (preg_match($re, $path)) {
                return true;
            }
        }

        return false;
    }
}
