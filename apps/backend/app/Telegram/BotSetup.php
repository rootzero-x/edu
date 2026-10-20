<?php

namespace App\Telegram;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bot profilini sozlamalardan Bot API'ga qo'llash: nom, tavsiflar, buyruqlar, menu tugmasi, avatar.
 * Har qadam alohida: bittasi xato bersa, qolganlari davom etadi.
 */
class BotSetup
{
    private const PHOTO_CACHE_KEY = 'tg.profile_photo_sha1';

    public function __construct(private TelegramClient $tg, private Settings $settings) {}

    /** @param callable(string $level, string $message): void $log */
    public function applyProfile(callable $log, bool $forcePhoto = false): void
    {
        $this->step($log, 'name', fn () => $this->texts('telegram.profile.name', 'setMyName', 'getMyName', 'name', 64));
        $this->step($log, 'short_description', fn () => $this->texts('telegram.profile.short_description', 'setMyShortDescription', 'getMyShortDescription', 'short_description', 120));
        $this->step($log, 'description', fn () => $this->texts('telegram.profile.description', 'setMyDescription', 'getMyDescription', 'description', 512));
        $this->step($log, 'commands', fn () => $this->commands());
        $this->step($log, 'menu_button', fn () => $this->menuButton());
        $this->step($log, 'photo', fn () => $this->photo($forcePhoto));
    }

    private function step(callable $log, string $name, callable $fn): void
    {
        try {
            $log('info', "$name: ".$fn());
        } catch (Throwable $e) {
            $log('error', "$name: ".$e->getMessage());
        }
    }

    /** Tillar bo'yicha matn. Joriy qiymat bilan bir xil bo'lsa so'rov yuborilmaydi (Telegram limitlari). */
    private function texts(string $key, string $setter, string $getter, string $field, int $max): string
    {
        $done = [];
        foreach ((array) $this->settings->get($key) as $lang => $value) {
            $value = (string) $value;
            if (mb_strlen($value) > $max) {
                throw new \InvalidArgumentException("[$lang] $max belgidan uzun.");
            }
            $params = $lang === 'default' ? [] : ['language_code' => (string) $lang];
            $current = ((array) $this->tg->call($getter, $params))[$field] ?? '';
            if ($current !== $value) {
                $this->tg->call($setter, $params + [$field => $value]);
                $done[] = $lang;
            }
        }

        return $done ? 'yangilandi ('.implode(', ', $done).')' : "o'zgarmagan";
    }

    private function commands(): string
    {
        $base = (array) $this->settings->get('telegram.profile.commands');
        $extra = (array) $this->settings->get('telegram.profile.admin_commands');
        $admins = array_map('intval', (array) $this->settings->get('telegram.admin_chat_ids'));
        $out = [];
        foreach ($base as $lang => $list) {
            $params = $lang === 'default' ? [] : ['language_code' => (string) $lang];
            $commands = $this->validCommands((array) $list);
            $this->tg->call('setMyCommands', $params + ['commands' => $commands]);
            // Adminlar chatida qo'shimcha buyruqlar ko'rinadi.
            $adminList = $this->validCommands(array_merge((array) $list, (array) ($extra[$lang] ?? $extra['default'] ?? [])));
            foreach ($admins as $chatId) {
                $this->tg->call('setMyCommands', $params + ['commands' => $adminList, 'scope' => ['type' => 'chat', 'chat_id' => $chatId]]);
            }
            $out[] = "$lang: ".count($commands);
        }

        return implode(', ', $out).($admins ? '; admin chatlar: '.count($admins) : '');
    }

    private function validCommands(array $list): array
    {
        $seen = [];
        foreach ($list as $c) {
            $cmd = strtolower(ltrim((string) ($c['command'] ?? ''), '/'));
            $desc = trim((string) ($c['description'] ?? ''));
            if (! preg_match('/^[a-z0-9_]{1,32}$/', $cmd) || $desc === '' || mb_strlen($desc) > 256) {
                throw new \InvalidArgumentException("noto'g'ri buyruq: ".json_encode($c, JSON_UNESCAPED_UNICODE));
            }
            $seen[$cmd] = ['command' => $cmd, 'description' => $desc];
        }

        return array_slice(array_values($seen), 0, 100);
    }

    private function menuButton(): string
    {
        $url = trim((string) $this->settings->get('telegram.miniapp_url'));
        if ($url === '') {
            $this->tg->call('setChatMenuButton', ['menu_button' => ['type' => 'commands']]);

            return 'buyruqlar ro\'yxati';
        }
        $this->tg->call('setChatMenuButton', ['menu_button' => [
            'type' => 'web_app', 'text' => (string) $this->settings->get('telegram.profile.menu_button_text'), 'web_app' => ['url' => $url],
        ]]);

        return "Mini App → $url";
    }

    /** setMyProfilePhoto (Bot API 9.4): faqat multipart yuklash. Rasm o'zgarmagan bo'lsa qayta yuklanmaydi. */
    private function photo(bool $force): string
    {
        $url = trim((string) $this->settings->get('telegram.profile.photo_url'));
        if ($url === '') {
            return "sozlanmagan (o'zgartirilmadi)";
        }
        $res = Http::timeout(20)->get($url);
        if (! $res->successful()) {
            throw new \RuntimeException("rasmni yuklab bo'lmadi: HTTP ".$res->status());
        }
        $body = $res->body();
        if (! str_starts_with($body, "\xFF\xD8\xFF")) {
            throw new \RuntimeException('rasm JPG formatida bo\'lishi kerak.');
        }
        $sha = sha1($body);
        if (! $force && Cache::get(self::PHOTO_CACHE_KEY) === $sha) {
            return "o'zgarmagan";
        }
        $this->tg->upload('setMyProfilePhoto', ['photo' => ['type' => 'static', 'photo' => 'attach://avatar']], ['avatar' => [$body, 'avatar.jpg']]);
        Cache::forever(self::PHOTO_CACHE_KEY, $sha);

        return 'yangilandi';
    }
}
