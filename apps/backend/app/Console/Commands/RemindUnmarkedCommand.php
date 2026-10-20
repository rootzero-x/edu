<?php

namespace App\Console\Commands;

use App\Models\StudyGroup;
use App\Support\SessionGenerator;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemindUnmarkedCommand extends Command
{
    protected $signature = 'tisu:remind-unmarked
        {--dry-run : Hech narsa yubormasdan kimga nima ketishini ko\'rsatish}
        {--skip : Hozir navbatdagilarni yubormasdan "o\'tkazildi" deb belgilash}';

    protected $description = "Davomati belgilanmagan darslar haqida o'qituvchiga eslatma";

    public function handle(Settings $settings, Notifier $notifier): int
    {
        if (! $settings->bool('automation.unmarked_reminder')) {
            return self::SUCCESS;
        }
        $after = $settings->int('automation.unmarked_after_minutes');
        $until = now()->subMinutes($after);
        $sessions = DB::table('class_sessions')
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->join('users', 'users.id', '=', 'study_groups.teacher_id')->where('users.active', true)
            ->where('class_sessions.cancelled', false)->where('study_groups.active', true)
            ->whereBetween('class_sessions.ends_at', [$until->copy()->subDay(), $until])
            // Faol a'zolardan kamida bittasi belgilanmagan bo'lsa (yarim belgilangan varaqa ham) — eslatma.
            ->whereExists(fn ($q) => $q->from('group_members')->whereColumn('group_members.group_id', 'study_groups.id')->whereNull('group_members.left_at')
                ->whereNotExists(fn ($a) => $a->from('attendances')->whereColumn('attendances.session_id', 'class_sessions.id')
                    ->whereColumn('attendances.student_id', 'group_members.student_id')))
            ->get(['class_sessions.id', 'class_sessions.starts_at', 'study_groups.name', 'users.id as teacher_id', 'users.first_name']);

        $items = $sessions->map(fn ($s) => [
            'user_id' => (int) $s->teacher_id, 'kind' => "attendance.unmarked:{$s->id}", 'key' => 'telegram.text.unmarked_reminder',
            'vars' => ['name' => $s->first_name, 'group' => $s->name, 'time' => \Illuminate\Support\Carbon::parse($s->starts_at)->format('d.m.Y H:i')],
        ])->all();
        AutomationCommands::deliver($this, $notifier, $items);

        return self::SUCCESS;
    }
}
