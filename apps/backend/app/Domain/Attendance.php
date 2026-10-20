<?php

namespace App\Domain;

use DateTimeInterface;

final class Attendance
{
    public const PRESENT = 'PRESENT';
    public const LATE = 'LATE';
    public const ABSENT = 'ABSENT';
    public const EXCUSED = 'EXCUSED';

    /**
     * @param int $graceMinutes      shu daqiqagacha kechikish PRESENT
     * @param int $absentAfterMinutes shundan ko'p kechikish ABSENT
     */
    public static function classifyArrival(
        DateTimeInterface $sessionStart,
        ?DateTimeInterface $arrivedAt,
        int $graceMinutes = 5,
        int $absentAfterMinutes = 30,
    ): string {
        if ($arrivedAt === null) {
            return self::ABSENT;
        }
        $lateMin = ($arrivedAt->getTimestamp() - $sessionStart->getTimestamp()) / 60;
        if ($lateMin <= $graceMinutes) {
            return self::PRESENT;
        }
        if ($lateMin <= $absentAfterMinutes) {
            return self::LATE;
        }

        return self::ABSENT;
    }

    /** Davomat foizi: PRESENT va LATE keldi, EXCUSED hisobga kirmaydi. */
    public static function rate(array $statuses): float
    {
        $counted = array_values(array_filter($statuses, fn ($s) => $s !== self::EXCUSED));
        if ($counted === []) {
            return 100.0;
        }
        $attended = count(array_filter($counted, fn ($s) => $s === self::PRESENT || $s === self::LATE));

        return round($attended / count($counted) * 100, 1);
    }
}
