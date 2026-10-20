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
use App\Support\Heartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

// Cron yurak urishi: har daqiqada birinchi bo'lib (holat kartasi va "cron to'xtadi" ogohlantirishi shunga qaraydi).
Schedule::call(fn () => Heartbeat::tick())->everyMinute()->name('tisu:heartbeat');

// Har bir vazifaning boshlanishi va natijasi system_status ga yoziladi (Heartbeat::JOBS — kutilgan oraliqlar).
$tracked = fn (Event $e, string $job): Event => $e
    ->before(fn () => Heartbeat::started($job))
    ->onSuccess(fn () => Heartbeat::ok($job))
    ->onFailure(fn () => Heartbeat::failed($job));

// Telegram navbati: har daqiqada ≈40 soniya (bo'sh bo'lsa darhol tugaydi).
$tracked(Schedule::command('tisu:outbox-drain')->everyMinute()->withoutOverlapping(5), 'tisu:outbox-drain');
$tracked(Schedule::command('tisu:generate-sessions')->dailyAt('03:10')->withoutOverlapping(), 'tisu:generate-sessions');
$tracked(Schedule::command('tisu:remind-lessons')->everyFiveMinutes()->withoutOverlapping(), 'tisu:remind-lessons');
$tracked(Schedule::command('tisu:remind-unmarked')->everyTenMinutes()->withoutOverlapping(), 'tisu:remind-unmarked');
$tracked(Schedule::command('tisu:cleanup')->dailyAt('04:20')->withoutOverlapping(), 'tisu:cleanup');
$tracked(Schedule::command('tisu:backup')->dailyAt('02:30')->withoutOverlapping(), 'tisu:backup');
// To'lovlar: bugungi kunni hisoblash (o'tkazib yuborilgan kunlar bilan) va qarz eslatmasi — vaqt app.timezone bo'yicha.
$tracked(Schedule::command('tisu:billing-accrue')->dailyAt('00:20')->withoutOverlapping(), 'tisu:billing-accrue');
$tracked(Schedule::command('tisu:billing-remind-debts')->dailyAt('10:00')->withoutOverlapping(), 'tisu:billing-remind-debts');
// Uyga vazifa: muddat eslatmasi va o'qituvchiga natija; egasiz qolgan yuklangan fayllarni tozalash.
$tracked(Schedule::command('tisu:remind-homework')->everyFifteenMinutes()->withoutOverlapping(), 'tisu:remind-homework');
$tracked(Schedule::command('tisu:files-sweep')->dailyAt('04:40')->withoutOverlapping(), 'tisu:files-sweep');
