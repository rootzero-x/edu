<?php

namespace App\Telegram;

final class Phone
{
    /** "998901234567", "+998 90 123-45-67" → "+998901234567" */
    public static function normalize(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        return '+'.$digits;
    }
}
