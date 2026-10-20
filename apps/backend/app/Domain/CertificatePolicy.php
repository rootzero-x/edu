<?php

namespace App\Domain;

final class CertificatePolicy
{
    public const MIN_ATTENDANCE = 75.0;

    /** @return list<'LOW_ATTENDANCE'|'QUIZ_NOT_PASSED'|'HAS_DEBT'> bo'sh bo'lsa — berish mumkin */
    public static function blockers(float $attendanceRate, bool $finalQuizPassed, int $debt): array
    {
        $r = [];
        if ($attendanceRate < self::MIN_ATTENDANCE) {
            $r[] = 'LOW_ATTENDANCE';
        }
        if (! $finalQuizPassed) {
            $r[] = 'QUIZ_NOT_PASSED';
        }
        if ($debt > 0) {
            $r[] = 'HAS_DEBT';
        }

        return $r;
    }
}
