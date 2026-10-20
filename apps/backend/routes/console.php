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

// Bot sozlash: php artisan tisu:telegram-setup → app/Console/Commands/TelegramSetupCommand.php
// Sozlamani CLI'dan o'rnatish: php artisan tisu:setting <key> '<json>' → app/Console/Commands/SettingCommand.php

// ── Avtomatik vazifalar ─────────────────────────────────────
// Server cron: * * * * * /opt/alt/php83/usr/bin/php ~/www/api.tisu.uzstudents.uz/artisan schedule:run
// Har bir vazifa sozlamadagi automation.* kaliti bilan yoqiladi/o'chiriladi.
use Illuminate\Support\Facades\Schedule;

Schedule::command('tisu:generate-sessions')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('tisu:remind-lessons')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('tisu:remind-unmarked')->everyTenMinutes()->withoutOverlapping();
Schedule::command('tisu:cleanup')->dailyAt('04:20')->withoutOverlapping();
