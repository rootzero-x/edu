<?php

namespace App\Console\Commands;

use App\Support\Settings;
use App\Telegram\BotSetup;
use App\Telegram\TelegramClient;
use Illuminate\Console\Command;

/** Token va webhook siri faqat .env da. Boshqa hamma narsa — telegram.* sozlamalaridan. */
class TelegramSetupCommand extends Command
{
    protected $signature = 'tisu:telegram-setup
        {--skip-webhook : Faqat profilni yangilash}
        {--skip-profile : Faqat getMe va webhook}
        {--force-photo : Avatar o\'zgarmagan bo\'lsa ham qayta yuklash}';

    protected $description = 'Telegram bot: getMe, webhook, profil (nom, tavsif, buyruqlar, menu tugmasi, avatar)';

    public function handle(TelegramClient $tg, Settings $settings, BotSetup $setup): int
    {
        $me = $tg->call('getMe');
        $settings->set(['telegram.bot_username' => $me['username']], null);
        $this->info("Bot: @{$me['username']}");

        if (! $this->option('skip-webhook')) {
            $secret = (string) config('services.telegram.webhook_secret');
            if (strlen($secret) < 32) {
                $this->error('TELEGRAM_WEBHOOK_SECRET .env da kamida 32 belgi bo\'lishi kerak.');

                return self::FAILURE;
            }
            $url = rtrim(config('app.url'), '/').'/v1/telegram/webhook';
            $tg->call('setWebhook', [
                'url' => $url, 'secret_token' => $secret,
                'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true,
            ]);
            $this->info("Webhook → $url");
        }

        $failed = false;
        if (! $this->option('skip-profile')) {
            $setup->applyProfile(function (string $level, string $message) use (&$failed) {
                $level === 'error' ? $this->error("  ✗ $message") : $this->line("  ✓ $message");
                $failed = $failed || $level === 'error';
            }, (bool) $this->option('force-photo'));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
