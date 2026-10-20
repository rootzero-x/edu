<?php

namespace App\Support;

final class Csv
{
    /** Excel formula injection'dan himoya: =, @, +, - bilan boshlanuvchi matn (oddiy telefon raqami/sondan tashqari) qiymat sifatida yoziladi. */
    public static function cell(string $v): string
    {
        return preg_match('/^[=@\t\r]|^[+\-](?!\d+$)/', $v) ? "'".$v : $v;
    }
}
