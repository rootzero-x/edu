<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Jadval to'qnashuvlari: bitta o'qituvchi yoki bitta xona bir vaqtda ikki guruhda bo'lmasin.
 * Xabar matnlari sozlamalarda (calendar.text.*), unda to'qnashgan guruh nomi ko'rsatiladi.
 */
final class ScheduleClash
{
    public function __construct(private Settings $settings) {}

    /**
     * Guruhning haftalik jadvalini boshqa faol guruhlar bilan solishtirish.
     *
     * @param  list<array{weekday:int|string, start_time:string, end_time:string}>  $slots
     * @return array{field:string, message:string}|null birinchi to'qnashuv
     */
    public function forSchedule(array $slots, int $teacherId, ?int $roomId, string $startDate, ?string $endDate, ?int $exceptGroupId = null): ?array
    {
        $slots = array_map(fn ($s) => ['weekday' => (int) $s['weekday'], 'start' => self::hm($s['start_time']), 'end' => self::hm($s['end_time'])], $slots);

        // Guruhning o'z jadvalida bir kunda ustma-ust vaqtlar.
        foreach ($slots as $i => $a) {
            foreach (array_slice($slots, $i + 1) as $b) {
                if ($a['weekday'] === $b['weekday'] && $a['start'] < $b['end'] && $b['start'] < $a['end']) {
                    return ['field' => 'schedules', 'message' => $this->text('calendar.text.self_clash', null, $b)];
                }
            }
        }

        $checkTeacher = $this->settings->bool('calendar.check_teacher_clash');
        $checkRoom = $roomId && $this->settings->bool('calendar.check_room_clash');
        if (! $slots || (! $checkTeacher && ! $checkRoom)) {
            return null;
        }
        $others = DB::table('group_schedules')
            ->join('study_groups', 'study_groups.id', '=', 'group_schedules.group_id')
            ->leftJoin('rooms', 'rooms.id', '=', 'study_groups.room_id')
            ->where('study_groups.active', true)
            ->when($exceptGroupId, fn ($q, $id) => $q->where('study_groups.id', '!=', $id))
            ->whereIn('group_schedules.weekday', array_unique(array_column($slots, 'weekday')))
            ->where(fn ($w) => $w->when($checkTeacher, fn ($q) => $q->orWhere('study_groups.teacher_id', $teacherId))
                ->when($checkRoom, fn ($q) => $q->orWhere('study_groups.room_id', $roomId)))
            // Sana oraliqlari kesishadi (tugash sanasi bo'sh — muddatsiz).
            ->when($endDate, fn ($q) => $q->where('study_groups.start_date', '<=', $endDate))
            ->where(fn ($w) => $w->whereNull('study_groups.end_date')->orWhere('study_groups.end_date', '>=', $startDate))
            ->orderBy('study_groups.name')
            ->get(['study_groups.id', 'study_groups.name', 'study_groups.teacher_id', 'study_groups.room_id', 'rooms.name as room_name',
                'group_schedules.weekday', 'group_schedules.start_time', 'group_schedules.end_time']);

        foreach ($slots as $a) {
            foreach ($others as $o) {
                $b = ['weekday' => (int) $o->weekday, 'start' => self::hm($o->start_time), 'end' => self::hm($o->end_time)];
                if ($a['weekday'] !== $b['weekday'] || ! ($a['start'] < $b['end'] && $b['start'] < $a['end'])) {
                    continue;
                }
                if ($checkTeacher && (int) $o->teacher_id === $teacherId) {
                    return ['field' => 'teacher_id', 'message' => $this->text('calendar.text.teacher_clash', $o, $b)];
                }
                if ($checkRoom && (int) $o->room_id === $roomId) {
                    return ['field' => 'room_id', 'message' => $this->text('calendar.text.room_clash', $o, $b)];
                }
            }
        }

        return null;
    }

    /**
     * Bitta mashg'ulot vaqti ko'chirilganda: shu vaqtda o'qituvchi yoki xonada boshqa (bekor qilinmagan) mashg'ulot bormi.
     *
     * @return array{field:string, message:string}|null
     */
    public function forSession(int $sessionId, int $teacherId, ?int $roomId, Carbon $starts, Carbon $ends): ?array
    {
        $checkTeacher = $this->settings->bool('calendar.check_teacher_clash');
        $checkRoom = $roomId && $this->settings->bool('calendar.check_room_clash');
        if (! $checkTeacher && ! $checkRoom) {
            return null;
        }
        $o = DB::table('class_sessions')
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->leftJoin('rooms', 'rooms.id', '=', 'study_groups.room_id')
            ->where('class_sessions.id', '!=', $sessionId)
            ->where('class_sessions.cancelled', false)
            ->where('class_sessions.starts_at', '<', $ends->format('Y-m-d H:i:s'))
            ->where('class_sessions.ends_at', '>', $starts->format('Y-m-d H:i:s'))
            ->where(fn ($w) => $w->when($checkTeacher, fn ($q) => $q->orWhere('study_groups.teacher_id', $teacherId))
                ->when($checkRoom, fn ($q) => $q->orWhere('study_groups.room_id', $roomId)))
            ->orderBy('class_sessions.starts_at')
            ->first(['study_groups.id', 'study_groups.name', 'study_groups.teacher_id', 'study_groups.room_id', 'rooms.name as room_name',
                'class_sessions.starts_at', 'class_sessions.ends_at']);
        if (! $o) {
            return null;
        }
        $s = Carbon::parse($o->starts_at);
        $slot = ['weekday' => $s->isoWeekday(), 'start' => $s->format('H:i'), 'end' => Carbon::parse($o->ends_at)->format('H:i'), 'date' => $s->format('d.m.Y')];
        $teacherHit = $checkTeacher && (int) $o->teacher_id === $teacherId;

        return $teacherHit
            ? ['field' => 'starts_at', 'message' => $this->text('calendar.text.teacher_clash', $o, $slot)]
            : ['field' => 'starts_at', 'message' => $this->text('calendar.text.room_clash', $o, $slot)];
    }

    private function text(string $key, ?object $other, array $slot): string
    {
        $days = (array) $this->settings->get('telegram.weekday_names');
        $day = ($slot['date'] ?? null) ?: ($days[$slot['weekday'] - 1] ?? (string) $slot['weekday']);

        return strtr((string) $this->settings->get($key), [
            '{group}' => (string) ($other->name ?? ''), '{room}' => (string) ($other->room_name ?? ''),
            '{day}' => $day, '{start}' => $slot['start'], '{end}' => $slot['end'],
        ]);
    }

    /** "14:00:00" / "14:00" → "14:00" (satr sifatida solishtiriladi). */
    private static function hm(string $t): string
    {
        return substr($t, 0, 5);
    }
}
