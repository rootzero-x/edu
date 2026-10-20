<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramClient
{
    public function token(): string
    {
        $token = (string) config('services.telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN .env da berilmagan.');
        }

        return $token;
    }

    public function call(string $method, array $params = []): array
    {
        $res = Http::timeout(10)->asJson()
            ->post("https://api.telegram.org/bot{$this->token()}/$method", $params)
            ->json();
        if (! ($res['ok'] ?? false)) {
            throw new RuntimeException("Telegram $method: ".($res['description'] ?? 'xato'));
        }

        return $res['result'];
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
        ], fn ($v) => $v !== null));
    }
}
