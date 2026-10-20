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

    public function call(string $method, array $params = []): mixed
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

    /** multipart/form-data so'rov (fayl yuklash). $files: maydon nomi => [kontent, fayl nomi]. */
    public function upload(string $method, array $params, array $files): mixed
    {
        $req = Http::timeout(30);
        foreach ($files as $name => [$contents, $filename]) {
            $req = $req->attach($name, $contents, $filename);
        }
        $fields = array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $params);
        $res = $req->post("https://api.telegram.org/bot{$this->token()}/$method", $fields)->json();
        if (! ($res['ok'] ?? false)) {
            throw new RuntimeException("Telegram $method: ".($res['description'] ?? 'xato'));
        }

        return $res['result'];
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): void
    {
        try {
            $this->call('editMessageText', array_filter([
                'chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'reply_markup' => $replyMarkup,
            ], fn ($v) => $v !== null));
        } catch (RuntimeException $e) {
            // Matn o'zgarmagan bo'lsa Telegram xato qaytaradi — bu xato emas.
            if (! str_contains($e->getMessage(), 'message is not modified')) {
                throw $e;
            }
        }
    }

    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert ?: null,
        ], fn ($v) => $v !== null));
    }
}
