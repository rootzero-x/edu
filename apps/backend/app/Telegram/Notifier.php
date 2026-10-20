<?php

namespace App\Telegram;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Foydalanuvchilarga Telegram xabar. Bot ulanmagan bo'lsa jim o'tkazib yuboradi, natija notifications_log ga yoziladi. */
class Notifier
{
    public function __construct(private TelegramClient $tg, private Settings $settings) {}

    public function sendTemplate(int $userId, string $kind, string $settingKey, array $vars): void
    {
        $chatId = DB::table('telegram_links')->where('user_id', $userId)->value('chat_id');
        $text = strtr((string) $this->settings->get($settingKey), array_combine(
            array_map(fn ($k) => '{'.$k.'}', array_keys($vars)), array_map('strval', array_values($vars))));
        $error = null;
        $sentAt = null;
        if (! $chatId) {
            $error = 'telegram_not_linked';
        } elseif ((string) config('services.telegram.bot_token') === '') {
            $error = 'bot_not_configured';
        } else {
            try {
                $this->tg->sendMessage((int) $chatId, $text);
                $sentAt = now();
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 250);
            }
        }
        DB::table('notifications_log')->insert([
            'user_id' => $userId, 'channel' => 'telegram', 'kind' => $kind,
            'payload' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
            'sent_at' => $sentAt, 'error' => $error, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
