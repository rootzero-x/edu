<?php

namespace App\Http\Controllers\Api;

use App\Support\Backup\BackupVault;
use App\Support\Heartbeat;
use App\Support\Settings;
use App\Support\SystemStatus;
use App\Telegram\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Admin uchun "Tizim holati" kartasi: cron, vazifalar, zaxira, Telegram yetkazish va arizalar. */
class SystemStatusController
{
    public function __invoke(Settings $settings): JsonResponse
    {
        $scheduler = Heartbeat::scheduler();
        $jobs = Heartbeat::jobs();
        $backup = SystemStatus::get('backup.last');
        $teacherPerms = (array) (((array) $settings->get('roles.permissions'))['TEACHER'] ?? []);
        $warnings = [];
        if ($scheduler['stale']) {
            $warnings[] = $scheduler['last_tick'] ? 'cron_stale' : 'cron_never';
        }
        if (collect($jobs)->contains('overdue', true)) {
            $warnings[] = 'job_overdue';
        }
        if (! BackupVault::keyConfigured()) {
            $warnings[] = 'backup_unencrypted';
        }
        if (in_array('course.manage', $teacherPerms, true)) {
            $warnings[] = 'teacher_course_manage';
        }

        return response()->json([
            'scheduler' => $scheduler,
            'jobs' => $jobs,
            'backup' => $backup ? $backup + ['encryption_configured' => BackupVault::keyConfigured()] : ['encryption_configured' => BackupVault::keyConfigured()],
            'telegram' => Outbox::stats(),
            'users' => [
                'pending' => DB::table('users')->whereNotNull('pending_at')->count(),
                'no_telegram' => DB::table('users')->where('active', true)->whereNotIn('id', DB::table('telegram_links')->select('user_id'))->count(),
            ],
            'warnings' => $warnings,
            'time' => now()->toIso8601String(),
        ]);
    }
}
