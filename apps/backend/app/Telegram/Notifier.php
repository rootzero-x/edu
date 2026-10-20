<?php

namespace App\Telegram;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Foydalanuvchilarga Telegram xabar. Bot ulanmagan yoki chat botni bloklagan bo'lsa — jim o'tkazib yuboradi.
 * Odatda xabar navbatga (telegram_outbox) qo'yiladi va cron/defer yuboradi; natija notifications_log ga yoziladi.
 * telegram.outbox_enabled o'chirilsa — eski usul: darhol yuborish.
 */
class Notifier
{
    /** Bu xatolar doimiy: shu xabar qayta urinilmaydi (eslatmalar takrorlanmasligi uchun "yuborilgan" hisoblanadi). */
    public const FINAL_ERRORS = ['telegram_not_linked', 'blocked', 'skipped', 'bot_not_configured'];

    public function __construct(private TelegramClient $tg, private Settings $settings, private Outbox $outbox) {}

    public function sendTemplate(int $userId, string $kind, string $settingKey, array $vars): void
    {
        $text = strtr((string) $this->settings->get($settingKey), array_combine(
            array_map(fn ($k) => '{'.$k.'}', array_keys($vars)), array_map('strval', array_values($vars))));
        $this->sendText($userId, $kind, $text);
    }

    public function sendText(int $userId, string $kind, string $text, ?array $markup = null): void
    {
        $link = DB::table('telegram_links')->where('user_id', $userId)->first(['chat_id', 'blocked_at']);
        $error = match (true) {
            ! $link => 'telegram_not_linked',
            $link->blocked_at !== null => 'blocked',
            (string) config('services.telegram.bot_token') === '' => 'bot_not_configured',
            default => null,
        };
        if ($error === null && $this->outbox->enabled()) {
            $this->outbox->enqueue($userId, (int) $link->chat_id, $kind, $text, $markup);
            $this->outbox->kick();

            return;
        }
        $sentAt = null;
        if ($error === null) {
            try {
                $this->tg->sendMessage((int) $link->chat_id, $text, $markup);
                $sentAt = now();
            } catch (TelegramApiException $e) {
                if ($e->unreachable()) {
                    Outbox::markBlocked((int) $link->chat_id, $e->getMessage());
                    $error = 'blocked';
                } else {
                    $error = mb_substr($e->getMessage(), 0, 250);
                }
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
