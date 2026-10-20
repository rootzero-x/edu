<?php

namespace App\Console\Commands;

use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Uyga vazifa avtomatikasi (cron, har 15 daqiqada):
 *  - muddati homework.due_reminder_hours ichida tugaydigan vazifani hali topshirmagan o'quvchilarga eslatma (bir marta);
 *  - muddati tugagan vazifa bo'yicha o'qituvchiga natija: necha o'quvchi topshirdi (bir marta).
 */
class RemindHomeworkCommand extends Command
{
    protected $signature = 'tisu:remind-homework
        {--dry-run : Hech narsa yubormasdan kimga nima ketishini ko\'rsatish}
        {--skip : Hozir navbatdagilarni yubormasdan "o\'tkazildi" deb belgilash}';

    protected $description = "Uyga vazifa muddati eslatmasi va o'qituvchiga natija";

    public function handle(Settings $settings, Notifier $notifier): int
    {
        if (! $settings->bool('homework.enabled')) {
            return self::SUCCESS;
        }
        $site = rtrim((string) $settings->get('site.url'), '/');

        if ($settings->bool('homework.due_reminder')) {
            $until = now()->addHours($settings->int('homework.due_reminder_hours'));
            $rows = DB::table('assignments')
                ->join('study_groups', 'study_groups.id', '=', 'assignments.group_id')
                ->join('group_members', 'group_members.group_id', '=', 'assignments.group_id')
                ->join('users', 'users.id', '=', 'group_members.student_id')
                ->where('study_groups.active', true)->whereNull('group_members.left_at')->where('users.active', true)
                ->whereBetween('assignments.due_at', [now(), $until])
                ->whereNotExists(fn ($q) => $q->from('assignment_submissions')
                    ->whereColumn('assignment_submissions.assignment_id', 'assignments.id')
                    ->whereColumn('assignment_submissions.student_id', 'users.id')
                    ->where(fn ($w) => $w->whereNotNull('assignment_submissions.submitted_at')->orWhereNotNull('assignment_submissions.score')))
                ->get(['assignments.id', 'assignments.title', 'assignments.due_at', 'study_groups.name as group_name', 'users.id as user_id', 'users.first_name']);
            AutomationCommands::deliver($this, $notifier, $rows->map(fn ($r) => [
                'user_id' => (int) $r->user_id, 'kind' => "homework.due:{$r->id}", 'key' => 'telegram.text.homework_due',
                'vars' => ['name' => $r->first_name, 'title' => $r->title, 'group' => $r->group_name,
                    'due' => Carbon::parse($r->due_at)->format('d.m.Y H:i'), 'url' => "$site/dashboard/homework/{$r->id}"],
            ])->all());
        }

        if ($settings->bool('homework.teacher_summary')) {
            $due = DB::table('assignments')
                ->join('study_groups', 'study_groups.id', '=', 'assignments.group_id')
                ->join('users', 'users.id', '=', 'study_groups.teacher_id')
                ->whereNull('assignments.summary_sent_at')->where('users.active', true)
                // Juda eski vazifalar (cron birinchi yoqilganda) uchun natija yuborilmaydi.
                ->whereBetween('assignments.due_at', [now()->subDays(2), now()])
                ->get(['assignments.id', 'assignments.group_id', 'assignments.title', 'study_groups.name as group_name', 'users.id as teacher_id', 'users.first_name']);
            $items = [];
            foreach ($due as $a) {
                $members = DB::table('group_members')->where('group_id', $a->group_id)->whereNull('left_at')->pluck('student_id');
                $submitted = DB::table('assignment_submissions')->where('assignment_id', $a->id)->whereIn('student_id', $members)->whereNotNull('submitted_at')->count();
                $items[] = ['user_id' => (int) $a->teacher_id, 'kind' => "homework.summary:{$a->id}", 'key' => 'telegram.text.homework_teacher_summary',
                    'vars' => ['name' => $a->first_name, 'group' => $a->group_name, 'title' => $a->title, 'submitted' => $submitted,
                        'total' => $members->count(), 'url' => "$site/dashboard/homework/{$a->id}"]];
            }
            AutomationCommands::deliver($this, $notifier, $items);
            if (! $this->option('dry-run') && $due->isNotEmpty()) {
                DB::table('assignments')->whereIn('id', $due->pluck('id'))->update(['summary_sent_at' => now()]);
            }
        }

        return self::SUCCESS;
    }
}
