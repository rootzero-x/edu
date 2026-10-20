<?php

namespace App\Support;

use App\Domain\ScheduleExpander;
use App\Models\StudyGroup;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/** Guruh jadvalidan darslar yaratish (takroran chaqirilsa dublikat bo'lmaydi). */
final class SessionGenerator
{
    public function __construct(private Settings $settings) {}

    /** @return array{created:int, from:string, to:string} */
    public function generate(StudyGroup $group, ?string $from = null, ?string $to = null): array
    {
        $tz = new DateTimeZone(config('app.timezone'));
        // Ilova soati (Carbon) — testlarda va boshqa joylarda bir xil "bugun".
        $today = DateTimeImmutable::createFromInterface(now($tz)->startOfDay());
        $start = max(new DateTimeImmutable($from ?? $today->format('Y-m-d'), $tz), new DateTimeImmutable($group->start_date->format('Y-m-d'), $tz));
        $end = new DateTimeImmutable($to ?? $today->modify('+'.$this->settings->int('attendance.sessions_ahead_days').' days')->format('Y-m-d'), $tz);
        if ($group->end_date) {
            $end = min($end, new DateTimeImmutable($group->end_date->format('Y-m-d'), $tz));
        }
        abort_if($start->diff($end)->days > 400 && $start <= $end, 422, 'Oraliq juda katta (400 kungacha).');

        $rows = $start > $end ? [] : array_map(fn ($s) => [
            'group_id' => $group->id,
            'starts_at' => $s['starts_at']->format('Y-m-d H:i:s'),
            'ends_at' => $s['ends_at']->format('Y-m-d H:i:s'),
            'cancelled' => false, 'created_at' => now(), 'updated_at' => now(),
        ], ScheduleExpander::expand($group->schedules->toArray(), $start, $end, $tz));

        return [
            'created' => $rows ? DB::table('class_sessions')->insertOrIgnore($rows) : 0,
            'from' => $start->format('Y-m-d'),
            'to' => $end->format('Y-m-d'),
        ];
    }
}
