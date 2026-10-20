<?php

namespace App\Support;

final class Csv
{
    /**
     * Excel formula injection'dan himoya: =, @, +, - bilan boshlanuvchi matn (oddiy sondan tashqari) qiymat sifatida yoziladi.
     * Telefon raqami (+998…) Excel'da songa aylanmasin (9,989E+11): ="+998…" ko'rinishida — formulaga faqat "+raqamlar" tushadi.
     */
    public static function cell(string $v): string
    {
        if (preg_match('/^\+\d{7,15}$/', $v)) {
            return '="'.$v.'"';
        }

        return preg_match('/^[=@\t\r]|^[+\-](?!\d+$)/', $v) ? "'".$v : $v;
    }

    /** O'nlik son sayt tiliga mos ajratuvchi bilan (uz/ru — vergul, en — nuqta): ; ajratuvchili CSV'da Excel uni son sifatida o'qiydi. */
    public static function decimal(float|int|null $v, string $locale): string
    {
        return $v === null ? '' : str_replace('.', $locale === 'en' ? '.' : ',', (string) $v);
    }
}
