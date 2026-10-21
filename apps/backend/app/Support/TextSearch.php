<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Matn qidiruvi: o'zbekcha apostroflar (' ʻ ʼ ’ ‘ `) farqsiz — "tolov" "To'lov" ni, "oralov" "O'ralov" ni topadi.
 * Ustun ham, so'rov ham apostroflardan tozalanib solishtiriladi (MySQL va SQLite'da ishlaydi).
 */
final class TextSearch
{
    public const APOSTROPHES = ["'", 'ʻ', 'ʼ', '’', '‘', '`'];

    public static function strip(string $s): string
    {
        return str_replace(self::APOSTROPHES, '', $s);
    }

    /** where (col1 LIKE %term% OR col2 LIKE %term% …), apostroflarsiz. Faqat apostroflardan iborat so'rov — hech narsa topmaydi. */
    public static function where(Builder $q, array $columns, string $term): Builder
    {
        $term = trim(self::strip($term));
        if ($term === '') {
            return $q->whereRaw('1 = 0');
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $q->where(function ($w) use ($columns, $like) {
            foreach ($columns as $col) {
                $w->orWhereRaw(self::expr($col).' like ?', [$like]);
            }
        });
    }

    /** REPLACE(REPLACE(… col …)) — ustundan apostroflarni olib tashlash. */
    private static function expr(string $column): string
    {
        $sql = $column;
        foreach (self::APOSTROPHES as $a) {
            $sql = "replace($sql, '".str_replace("'", "''", $a)."', '')";
        }

        return $sql;
    }
}
