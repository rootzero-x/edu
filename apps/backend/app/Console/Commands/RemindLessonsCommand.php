<?php

namespace App\Console\Commands;

use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemindLessonsCommand extends Command
{
    protected $signature = 'tisu:remind-lessons
        {--dry-run : Hech narsa yubormasdan kimga nima ketishini ko\'rsatish}
        {--skip : Hozir navbatdagilarni yubormasdan "o\'tkazildi" deb belgilash}';

    protected $description = "Yaqinlashayotgan darslar haqida o'quvchilarga, o'qituvchiga (va ota-onalarga) Telegram eslatma";

    public function handle(Settings $settings, Notifier $notifier): int
    {
        $students = $settings->bool('automation.lesson_reminder');
        $teachers = $settings->bool('automation.lesson_reminder_teachers');
        if (! $students && ! $teachers) {
            return self::SUCCESS;
        }
        $withParents = $settings->bool('automation.lesson_reminder_parents');
        $sessions = DB::table('class_sessions')
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->where('class_sessions.cancelled', false)->where('study_groups.active', true)
            ->whereBetween('class_sessions.starts_at', [now(), now()->addMinutes($settings->int('automation.lesson_reminder_minutes'))])
            ->get(['class_sessions.id', 'class_sessions.starts_at', 'class_sessions.topic', 'study_groups.id as group_id',
                'study_groups.name', 'study_groups.room', 'study_groups.teacher_id']);

        $items = [];
        foreach ($sessions as $s) {
            $kind = "lesson.reminder:{$s->id}";
            $vars = ['group' => $s->name, 'time' => substr((string) $s->starts_at, 11, 5), 'room' => $s->room ?: '—', 'topic' => $s->topic ?? ''];
            $members = DB::table('group_members')->join('users', 'users.id', '=', 'group_members.student_id')
                ->where('group_members.group_id', $s->group_id)->whereNull('group_members.left_at')->where('users.active', true)
                ->get(['users.id', 'users.first_name']);
            if ($students) {
                foreach ($members as $st) {
                    $items[] = ['user_id' => (int) $st->id, 'kind' => $kind, 'key' => 'telegram.text.lesson_reminder', 'vars' => $vars + ['name' => $st->first_name]];
                    if (! $withParents) {
                        continue;
                    }
                    // Ota-onaga alohida matn: "Farzandingiz {child}ning ..." (o'quvchiga yozilgandek emas).
                    $parents = DB::table('parent_child')->join('users', 'users.id', '=', 'parent_child.parent_id')
                        ->where('parent_child.child_id', $st->id)->get(['users.id', 'users.first_name']);
                    foreach ($parents as $p) {
                        $items[] = ['user_id' => (int) $p->id, 'kind' => $kind, 'key' => 'telegram.text.lesson_reminder_parent',
                            'vars' => $vars + ['name' => $p->first_name, 'child' => $st->first_name]];
                    }
                }
            }
            // O'qituvchi: guruhda o'quvchi bo'lsa.
            $teacher = $teachers && $members->isNotEmpty()
                ? DB::table('users')->where('id', $s->teacher_id)->where('active', true)->first(['id', 'first_name'])
                : null;
            if ($teacher) {
                $items[] = ['user_id' => (int) $teacher->id, 'kind' => "lesson.reminder.teacher:{$s->id}", 'key' => 'telegram.text.lesson_reminder_teacher',
                    'vars' => $vars + ['name' => $teacher->first_name, 'students' => $members->count()]];
            }
        }
        AutomationCommands::deliver($this, $notifier, $items);

        return self::SUCCESS;
    }
}
