<?php

namespace App\Telegram;

/** Telegram Mini App initData imzosini tekshirish (core.telegram.org/bots/webapps#validating-data). */
final class InitData
{
    /** @return array|null tekshirilgan maydonlar (user — massiv), yoki null */
    public static function verify(string $initData, string $botToken, int $maxAgeSeconds, ?int $now = null): ?array
    {
        parse_str($initData, $fields);
        $hash = $fields['hash'] ?? null;
        if (! is_string($hash) || $hash === '') {
            return null;
        }
        unset($fields['hash']);
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        if (! hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
            return null;
        }
        $age = ($now ?? time()) - (int) ($fields['auth_date'] ?? 0);
        if ($age < 0 || $age > $maxAgeSeconds) {
            return null;
        }
        $fields['user'] = json_decode($fields['user'] ?? 'null', true);

        return is_array($fields['user']) && isset($fields['user']['id']) ? $fields : null;
    }
}
