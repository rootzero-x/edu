<?php

namespace App\Console\Commands;

use App\Models\StudyGroup;
use App\Support\SessionGenerator;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutomationCommands
{
    /** Ota-onalar va o'quvchilar uchun umumiy: xabar oldin yuborilganmi. */
    public static function alreadySent(int $userId, string $kind): bool
    {
        return DB::table('notifications_log')->where(['user_id' => $userId, 'kind' => $kind])->exists();
    }
}
