<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Throwable;

/** Bot holati: getMe va getWebhookInfo (token chiqarilmaydi). */
class TelegramStatusCommand extends Command
{
    protected $signature = 'tisu:telegram-status';

    protected $description = 'Telegram bot va webhook holatini ko\'rsatish';

    public function handle(TelegramClient $tg): int
    {
        try {
            $me = $tg->call('getMe');
            $wh = $tg->call('getWebhookInfo');
        } catch (Throwable $e) {
            $this->error('Xato: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->line("Bot: @{$me['username']} (id {$me['id']})");
        $this->line('Webhook: '.($wh['url'] ?? '—'));
        $this->line('Kutilayotgan yangilanishlar: '.($wh['pending_update_count'] ?? 0));
        $this->line('Qabul qilinadigan turlar: '.implode(', ', $wh['allowed_updates'] ?? ['(standart)']));
        if (! empty($wh['last_error_message'])) {
            $this->warn('Oxirgi xato: '.$wh['last_error_message'].' ('.date('Y-m-d H:i', (int) ($wh['last_error_date'] ?? 0)).')');
        } else {
            $this->info('Xatolar yo\'q.');
        }

        return self::SUCCESS;
    }
}
