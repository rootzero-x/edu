<?php

namespace App\Console\Commands;

use App\Models\StudyGroup;
use App\Support\SessionGenerator;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemindLessonsCommand extends Command
{
    protected $signature = 'tisu:remind-lessons';

    protected $description = "Yaqinlashayotgan darslar haqida o'quvchilarga (va ota-onalarga) Telegram eslatma";

    public function handle(Settings $settings, Notifier $notifier): int
    {
        if (! $settings->bool('automation.lesson_reminder')) {
            return self::SUCCESS;
        }
        $minutes = $settings->int('automation.lesson_reminder_minutes');
        $withParents = $settings->bool('automation.lesson_reminder_parents');
        $sessions = DB::table('class_sessions')
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->where('class_sessions.cancelled', false)->where('study_groups.active', true)
            ->whereBetween('class_sessions.starts_at', [now(), now()->addMinutes($minutes)])
            ->get(['class_sessions.id', 'class_sessions.starts_at', 'class_sessions.topic', 'study_groups.id as group_id', 'study_groups.name', 'study_groups.room']);

        $sent = 0;
        foreach ($sessions as $s) {
            $kind = "lesson.reminder:{$s->id}";
            $students = DB::table('group_members')->join('users', 'users.id', '=', 'group_members.student_id')
                ->where('group_members.group_id', $s->group_id)->whereNull('group_members.left_at')->where('users.active', true)
                ->get(['users.id', 'users.first_name']);
            foreach ($students as $st) {
                $recipients = [$st->id];
                if ($withParents) {
                    $recipients = array_merge($recipients, DB::table('parent_child')->where('child_id', $st->id)->pluck('parent_id')->all());
                }
                foreach ($recipients as $uid) {
                    if (AutomationCommands::alreadySent((int) $uid, $kind)) {
                        continue;
                    }
                    $notifier->sendTemplate((int) $uid, $kind, 'telegram.text.lesson_reminder', [
                        'name' => $st->first_name, 'group' => $s->name, 'time' => substr((string) $s->starts_at, 11, 5),
                        'room' => $s->room ?? '—', 'topic' => $s->topic ?? '',
                    ]);
                    $sent++;
                }
            }
        }
        $this->info("Eslatmalar: $sent");

        return self::SUCCESS;
    }
}
