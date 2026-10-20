<?php

namespace App\Domain;

final class CertificatePolicy
{
    /** @return list<'NO_ATTENDANCE_DATA'|'LOW_ATTENDANCE'|'QUIZ_NOT_PASSED'|'HAS_DEBT'> bo'sh bo'lsa — berish mumkin */
    public static function blockers(
        float $attendanceRate,
        bool $finalQuizPassed,
        int $debt,
        float $minAttendance,
        bool $requireFinalQuiz,
        bool $requireNoDebt,
        int $markedSessions,
        int $minMarkedSessions,
    ): array
    {
        $r = [];
        if ($markedSessions < $minMarkedSessions) {
            $r[] = 'NO_ATTENDANCE_DATA';
        }
        if ($attendanceRate < $minAttendance) {
            $r[] = 'LOW_ATTENDANCE';
        }
        if ($requireFinalQuiz && ! $finalQuizPassed) {
            $r[] = 'QUIZ_NOT_PASSED';
        }
        if ($requireNoDebt && $debt > 0) {
            $r[] = 'HAS_DEBT';
        }

        return $r;
    }
}
