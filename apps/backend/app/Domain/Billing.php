<?php

namespace App\Domain;

/** Barcha summalar so'mda, butun son. */
final class Billing
{
    public static function applyDiscount(int $amount, ?string $kind, int $value = 0): int
    {
        if ($kind === null) {
            return $amount;
        }
        $off = $kind === 'PERCENT' ? (int) round($amount * $value / 100) : $value;

        return max(0, $amount - $off);
    }

    /** Oy o'rtasida qo'shilgan o'quvchi: oyning qolgan darslariga proporsional. */
    public static function proratedMonthlyFee(int $monthlyFee, int $totalLessons, int $remainingLessons): int
    {
        if ($totalLessons <= 0) {
            return 0;
        }
        $r = min(max($remainingLessons, 0), $totalLessons);

        return (int) round($monthlyFee * $r / $totalLessons);
    }

    /**
     * Musbat — qarz, manfiy — oldindan to'langan.
     *
     * @param iterable<array{type:string, amount:int}> $entries
     */
    public static function balance(iterable $entries): int
    {
        $b = 0;
        foreach ($entries as $e) {
            $b += $e['type'] === 'PAYMENT' ? -$e['amount'] : $e['amount'];
        }

        return $b;
    }
}
