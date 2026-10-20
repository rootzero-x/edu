<?php

namespace App\Telegram;

use RuntimeException;

/** Telegram Bot API xatosi: error_code (403 — bot bloklangan, 429 — juda tez) va retry_after. */
class TelegramApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $errorCode = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $errorCode);
    }

    /** Foydalanuvchi botni bloklagan, akkaunti o'chirilgan yoki chat mavjud emas — qayta urinishning foydasi yo'q. */
    public function unreachable(): bool
    {
        if ($this->errorCode === 403) {
            return true;
        }
        $m = strtolower($this->getMessage());

        return $this->errorCode === 400 && (str_contains($m, 'chat not found') || str_contains($m, 'user is deactivated')
            || str_contains($m, 'bot was blocked') || str_contains($m, 'peer_id_invalid'));
    }

    public function rateLimited(): bool
    {
        return $this->errorCode === 429;
    }
}
