<?php

use App\Domain\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// Birinchi super-admin: php artisan tisu:create-admin +998901234567 Ism
// Parol interaktiv so'raladi va hech qayerga yozilmaydi.
Artisan::command('tisu:create-admin {phone} {first_name}', function (string $phone, string $first_name) {
    $min = app(\App\Support\Settings::class)->int('auth.min_password_length');
    $password = $this->secret("Parol (kamida $min belgi)");
    if (strlen((string) $password) < $min) {
        $this->error('Parol juda qisqa.');

        return 1;
    }
    $user = User::updateOrCreate(['phone' => $phone], [
        'first_name' => $first_name, 'password' => $password, 'role' => Role::SuperAdmin, 'active' => true,
    ]);
    $this->info("Super-admin tayyor: #{$user->id} {$user->phone}");
})->purpose('Super-admin yaratish');

// Bot sozlash: token va webhook siri .env da bo'lishi kerak.
// php artisan tisu:telegram-setup  → getMe (username sozlamaga yoziladi) + setWebhook
Artisan::command('tisu:telegram-setup', function () {
    $tg = app(\App\Telegram\TelegramClient::class);
    $secret = (string) config('services.telegram.webhook_secret');
    if (strlen($secret) < 32) {
        $this->error('TELEGRAM_WEBHOOK_SECRET .env da kamida 32 belgi bo\'lishi kerak.');

        return 1;
    }
    $me = $tg->call('getMe');
    app(\App\Support\Settings::class)->set(['telegram.bot_username' => $me['username']], null);
    $url = rtrim(config('app.url'), '/').'/v1/telegram/webhook';
    $tg->call('setWebhook', ['url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => true]);
    $this->info("Bot @{$me['username']} → $url");
})->purpose('Telegram bot webhook va username sozlash');
