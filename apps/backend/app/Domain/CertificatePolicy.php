<?php

namespace App\Domain;

final class CertificatePolicy
{
    /** @return list<'NO_ATTENDANCE_DATA'|'LOW_ATTENDANCE'|'QUIZ_NOT_PASSED'> bo'sh bo'lsa — berish mumkin */
    public static function blockers(
        float $attendanceRate,
        bool $finalQuizPassed,
        float $minAttendance,
        bool $requireFinalQuiz,
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

        return $r;
    }
}
