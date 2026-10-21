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

        return $this->result($method, $res);
    }

    /** Javobni tekshirish: xato bo'lsa TelegramApiException (error_code, retry_after bilan). */
    private function result(string $method, mixed $res): mixed
    {
        if (! is_array($res) || ! ($res['ok'] ?? false)) {
            throw new TelegramApiException(
                "Telegram $method: ".(is_array($res) ? ($res['description'] ?? 'xato') : "javob yo'q"),
                is_array($res) ? (int) ($res['error_code'] ?? 0) : 0,
                is_array($res) && isset($res['parameters']['retry_after']) ? (int) $res['parameters']['retry_after'] : null,
            );
        }

        return $res['result'] ?? null;
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
        ], fn ($v) => $v !== null));
    }

    /** multipart/form-data so'rov (fayl yuklash). $files: maydon nomi => [kontent yoki resurs, fayl nomi]. */
    public function upload(string $method, array $params, array $files, int $timeout = 30): mixed
    {
        $req = Http::timeout($timeout);
        foreach ($files as $name => [$contents, $filename]) {
            $req = $req->attach($name, $contents, $filename);
        }
        $fields = array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $params);
        $res = $req->post("https://api.telegram.org/bot{$this->token()}/$method", $fields)->json();

        return $this->result($method, $res);
    }

    /** Hujjat yuborish (zaxira fayllari uchun, Telegram chegarasi — 50 MB). $contents — satr yoki fayl resursi. */
    public function sendDocument(int $chatId, mixed $contents, string $filename, string $caption = ''): mixed
    {
        return $this->upload('sendDocument', array_filter(['chat_id' => $chatId, 'caption' => mb_substr($caption, 0, 1000)], fn ($v) => $v !== ''),
            ['document' => [$contents, $filename]], 300);
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
        try {
            $this->call('answerCallbackQuery', array_filter([
                'callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => $alert ?: null,
            ], fn ($v) => $v !== null));
        } catch (TelegramApiException $e) {
            // Eskirgan tugma bosilishi (javob 15 s dan kech qoldi): amal bajarilgan, faqat tugmadagi "soat" o'chmagan.
            // Bu xato emas — ishlov to'xtatilmaydi va adminga ogohlantirish ketmaydi.
            if (! str_contains($e->getMessage(), 'query is too old') && ! str_contains($e->getMessage(), 'query ID is invalid')) {
                throw $e;
            }
        }
    }
}
