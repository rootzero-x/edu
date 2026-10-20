<?php

namespace App\Domain;

use Carbon\CarbonInterface;

/** Uyga vazifa holati (sof qoida, bazasiz). */
final class Homework
{
    public const PENDING = 'PENDING';

    public const SUBMITTED = 'SUBMITTED';

    public const LATE = 'LATE';

    public const GRADED = 'GRADED';

    public const MISSED = 'MISSED';

    public static function status(?CarbonInterface $submittedAt, ?int $score, ?CarbonInterface $dueAt, CarbonInterface $now): string
    {
        if ($score !== null) {
            return self::GRADED;
        }
        if ($submittedAt) {
            return $dueAt && $submittedAt->greaterThan($dueAt) ? self::LATE : self::SUBMITTED;
        }

        return $dueAt && $now->greaterThan($dueAt) ? self::MISSED : self::PENDING;
    }

    /** Bahoni foizga (turli shkaladagi baholarni solishtirish uchun). */
    public static function percent(?int $score, int $max): ?float
    {
        return $score === null || $max <= 0 ? null : round(min($score, $max) / $max * 100, 1);
    }
}
