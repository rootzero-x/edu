<?php

namespace App\Telegram;

/** @deprecated App\Support\Phone ishlating. Eski chaqiruvlar uchun qoldirilgan. */
final class Phone
{
    public static function normalize(string $raw): ?string
    {
        return \App\Support\Phone::normalize($raw);
    }
}
