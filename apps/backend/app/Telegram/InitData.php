<?php

namespace App\Telegram;

/** Telegram Mini App initData imzosini tekshirish (core.telegram.org/bots/webapps#validating-data). */
final class InitData
{
    /** @return array|null tekshirilgan maydonlar (user — massiv), yoki null */
    public static function verify(string $initData, string $botToken, int $maxAgeSeconds, ?int $now = null): ?array
    {
        [$fields] = self::inspect($initData, $botToken, $maxAgeSeconds, $now);

        return $fields;
    }

    /**
     * Imzo va muddatni tekshiradi va sababini qaytaradi: 'ok', 'expired' (imzo to'g'ri, lekin eski —
     * Mini App'ni qayta ochish kerak) yoki 'invalid'.
     *
     * @return array{0: array|null, 1: 'ok'|'expired'|'invalid'}
     */
    public static function inspect(string $initData, string $botToken, int $maxAgeSeconds, ?int $now = null): array
    {
        parse_str($initData, $fields);
        $hash = $fields['hash'] ?? null;
        if (! is_string($hash) || $hash === '' || $botToken === '') {
            return [null, 'invalid'];
        }
        unset($fields['hash']);
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        if (! hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
            return [null, 'invalid'];
        }
        $fields['user'] = json_decode($fields['user'] ?? 'null', true);
        if (! is_array($fields['user']) || ! isset($fields['user']['id'])) {
            return [null, 'invalid'];
        }
        $age = ($now ?? time()) - (int) ($fields['auth_date'] ?? 0);
        // Telefon soati biroz oldinda bo'lishi mumkin — kichik og'ish kechiriladi.
        if ($age < -300) {
            return [null, 'invalid'];
        }
        if ($age > $maxAgeSeconds) {
            return [null, 'expired'];
        }

        return [$fields, 'ok'];
    }

    /** Throttle kaliti uchun initData'dagi Telegram user id (imzo tekshirilmagan — faqat hisoblagich uchun). */
    public static function unverifiedUserId(string $initData): ?int
    {
        parse_str($initData, $fields);
        $user = is_string($fields['user'] ?? null) ? json_decode($fields['user'], true) : null;

        return is_array($user) && is_numeric($user['id'] ?? null) ? (int) $user['id'] : null;
    }
}
