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
    protected $signature = 'tisu:remind-unmarked';

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
            ->join('users', 'users.id', '=', 'study_groups.teacher_id')
            ->where('class_sessions.cancelled', false)->where('study_groups.active', true)
            ->whereBetween('class_sessions.ends_at', [$until->copy()->subDay(), $until])
            ->whereNotExists(fn ($q) => $q->from('attendances')->whereColumn('attendances.session_id', 'class_sessions.id'))
            ->whereExists(fn ($q) => $q->from('group_members')->whereColumn('group_members.group_id', 'study_groups.id')->whereNull('group_members.left_at'))
            ->get(['class_sessions.id', 'class_sessions.starts_at', 'study_groups.name', 'users.id as teacher_id', 'users.first_name']);

        $sent = 0;
        foreach ($sessions as $s) {
            $kind = "attendance.unmarked:{$s->id}";
            if (AutomationCommands::alreadySent((int) $s->teacher_id, $kind)) {
                continue;
            }
            $notifier->sendTemplate((int) $s->teacher_id, $kind, 'telegram.text.unmarked_reminder', [
                'name' => $s->first_name, 'group' => $s->name, 'time' => \Illuminate\Support\Carbon::parse($s->starts_at)->format('d.m.Y H:i'),
            ]);
            $sent++;
        }
        $this->info("Eslatmalar: $sent");

        return self::SUCCESS;
    }
}
