<?php

namespace App\Console\Commands;

use App\Models\StudyGroup;
use App\Support\SessionGenerator;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupCommand extends Command
{
    protected $signature = 'tisu:cleanup';

    protected $description = 'Eskirgan kirish so\'rovlari, tokenlar va eski bildirishnomalarni tozalash';

    public function handle(Settings $settings): int
    {
        $a = DB::table('telegram_login_requests')->where('expires_at', '<', now()->subDay())->delete();
        $b = DB::table('personal_access_tokens')->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
        $c = DB::table('notifications_log')->where('created_at', '<', now()->subDays($settings->int('automation.cleanup_days')))->delete();
        // Navbat: yuborilgan/yakunlangan xabarlar va tugagan tarqatish paketlari ham shu muddatdan keyin.
        $cutoff = now()->subDays($settings->int('automation.cleanup_days'));
        $d = DB::table('telegram_outbox')->where('status', '!=', 'pending')->where('updated_at', '<', $cutoff)->delete();
        DB::table('telegram_batches')->whereNotNull('finished_at')->where('finished_at', '<', $cutoff)->delete();
        $this->info("Tozalandi: login so'rovlari $a, tokenlar $b, bildirishnomalar $c, navbat $d");

        return self::SUCCESS;
    }
}
