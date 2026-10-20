<?php

namespace App\Domain;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;

/** Haftalik jadvaldan aniq dars vaqtlarini hosil qilish (sof funksiya). */
final class ScheduleExpander
{
    /**
     * @param list<array{weekday:int, start_time:string, end_time:string}> $slots weekday: 1=Du … 7=Ya, vaqt "HH:MM[:SS]"
     * @return list<array{starts_at:DateTimeImmutable, ends_at:DateTimeImmutable}>
     */
    public static function expand(array $slots, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $tz): array
    {
        $out = [];
        $days = new DatePeriod($from->setTimezone($tz)->setTime(0, 0), new DateInterval('P1D'), $to->setTimezone($tz)->setTime(0, 0)->modify('+1 day'));
        foreach ($days as $day) {
            $wd = (int) $day->format('N');
            foreach ($slots as $s) {
                if ((int) $s['weekday'] !== $wd) {
                    continue;
                }
                [$sh, $sm] = array_map('intval', explode(':', $s['start_time']));
                [$eh, $em] = array_map('intval', explode(':', $s['end_time']));
                $out[] = ['starts_at' => $day->setTime($sh, $sm), 'ends_at' => $day->setTime($eh, $em)];
            }
        }
        usort($out, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);

        return $out;
    }
}
