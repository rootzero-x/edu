<?php

namespace App\Domain;

use DateTimeInterface;

final class QuizGrader
{
    private static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }

    /**
     * @param array{type:string, points:int, correct:list<string>} $q
     * @param list<string>|null $given
     */
    public static function scoreQuestion(array $q, ?array $given): int
    {
        if (! $given) {
            return 0;
        }
        if ($q['type'] === 'TEXT') {
            $ok = array_map(self::norm(...), $q['correct']);

            return in_array(self::norm((string) $given[0]), $ok, true) ? $q['points'] : 0;
        }
        $want = array_unique($q['correct']);
        $got = array_unique($given);
        if ($q['type'] === 'SINGLE') {
            return count($got) === 1 && in_array($given[0], $want, true) ? $q['points'] : 0;
        }
        // MULTIPLE: faqat to'liq mos bo'lsa ball, qisman ball yo'q.
        sort($want);
        sort($got);

        return $want === $got ? $q['points'] : 0;
    }

    /**
     * @param array<string, array{type:string, points:int, correct:list<string>}> $questions id => savol
     * @param array<string, list<string>> $answers id => javoblar
     * @return array{score:int, max_score:int, percent:float, passed:bool}
     */
    public static function grade(array $questions, array $answers, int $passPercent): array
    {
        $max = 0;
        $score = 0;
        foreach ($questions as $id => $q) {
            $max += $q['points'];
            $score += self::scoreQuestion($q, $answers[$id] ?? null);
        }
        $percent = $max === 0 ? 0.0 : round($score / $max * 100, 1);

        return ['score' => $score, 'max_score' => $max, 'percent' => $percent, 'passed' => $percent >= $passPercent];
    }

    /** @return null|'NO_ATTEMPTS_LEFT'|'TIME_EXPIRED' — null bo'lsa topshirish mumkin */
    public static function submitBlocker(
        int $maxAttempts,
        ?int $timeLimitSec,
        int $previousAttempts,
        DateTimeInterface $startedAt,
        DateTimeInterface $now,
        int $graceSec = 15,
    ): ?string {
        if ($previousAttempts >= $maxAttempts) {
            return 'NO_ATTEMPTS_LEFT';
        }
        if ($timeLimitSec !== null && $now->getTimestamp() - $startedAt->getTimestamp() > $timeLimitSec + $graceSec) {
            return 'TIME_EXPIRED';
        }

        return null;
    }
}
