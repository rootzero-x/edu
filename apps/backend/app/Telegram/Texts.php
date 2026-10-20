<?php

namespace App\Telegram;

use App\Support\Settings;

/** Bot matnlari: sozlamadan olinadi, {o'zgaruvchi}lar qo'yiladi. {site} har doim mavjud. */
final class Texts
{
    public function __construct(private Settings $settings) {}

    public function get(string $key, array $vars = []): string
    {
        $vars += ['site' => $this->settings->get('site.name')];

        return strtr((string) $this->settings->get($key), array_combine(
            array_map(fn ($k) => '{'.$k.'}', array_keys($vars)), array_map('strval', array_values($vars))));
    }
}
