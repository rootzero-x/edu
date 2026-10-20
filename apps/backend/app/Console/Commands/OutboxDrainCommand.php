<?php

namespace App\Console\Commands;

use App\Telegram\Outbox;
use Illuminate\Console\Command;

/** Telegram navbatini bo'shatish. Cron har daqiqada ishga tushiradi; navbat bo'sh bo'lsa darhol tugaydi. */
class OutboxDrainCommand extends Command
{
    protected $signature = 'tisu:outbox-drain {--seconds=40 : Eng ko\'p ishlash vaqti} {--limit=0 : Eng ko\'p xabar (0 — cheklovsiz)}';

    protected $description = 'Telegram xabarlari navbatini yuborish (tezlik chegarasi, 429/403 hisobga olinadi)';

    public function handle(Outbox $outbox): int
    {
        if ((string) config('services.telegram.bot_token') === '') {
            $this->line('TELEGRAM_BOT_TOKEN berilmagan.');

            return self::SUCCESS;
        }
        $limit = (int) $this->option('limit');
        $stats = $outbox->drain($limit > 0 ? $limit : PHP_INT_MAX, max(1.0, (float) $this->option('seconds')));
        if (! empty($stats['locked'])) {
            $this->line('Boshqa jarayon yubormoqda.');

            return self::SUCCESS;
        }
        $this->line("Yuborildi: {$stats['sent']}, xato: {$stats['failed']}, bloklagan: {$stats['blocked']}, qayta: {$stats['retried']}, navbatda: {$stats['left']}");

        return self::SUCCESS;
    }
}
