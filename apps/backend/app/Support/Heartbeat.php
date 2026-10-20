<?php

namespace App\Support;

use App\Telegram\BotAdmin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cron yurak urishi va avtomatik vazifalar sog'lig'i.
 *  - schedule:run har daqiqada tick() ni chaqiradi (scheduler.tick);
 *  - har bir kuzatiladigan vazifa boshlanishi/natijasi job.<nom> ga yoziladi;
 *  - vazifa 2× oralig'idan ko'p muvaffaqiyatli ishlamasa yoki cron to'xtasa — adminlarga (cheklangan) ogohlantirish.
 * Cron to'xtaganini cronning o'zi aniqlay olmaydi: buni veb-so'rovlar (checkFromWeb) va /health qiladi.
 */
final class Heartbeat
{
    /** Kuzatiladigan vazifalar: nom => kutilgan oraliq (daqiqa). routes/console.php dagi jadval bilan mos. */
    public const JOBS = [
        'tisu:outbox-drain' => 1,
        'tisu:remind-lessons' => 5,
        'tisu:remind-unmarked' => 10,
        'tisu:generate-sessions' => 1440,
        'tisu:cleanup' => 1440,
        'tisu:backup' => 1440,
        'tisu:billing-accrue' => 1440,
        'tisu:billing-remind-debts' => 1440,
    ];

    public static function tick(): void
    {
        $now = now()->toIso8601String();
        if (! SystemStatus::get('scheduler.first_tick')) {
            SystemStatus::put('scheduler.first_tick', $now);
        }
        SystemStatus::put('scheduler.tick', $now);
        self::checkJobs();
    }

    public static function started(string $job): void
    {
        SystemStatus::merge("job.$job", ['last_run_at' => now()->toIso8601String()]);
    }

    public static function ok(string $job): void
    {
        SystemStatus::merge("job.$job", ['last_ok_at' => now()->toIso8601String(), 'last_error' => null]);
    }

    public static function failed(string $job, ?string $error = null): void
    {
        SystemStatus::merge("job.$job", ['last_failed_at' => now()->toIso8601String(), 'last_error' => mb_substr((string) ($error ?: 'exit != 0'), 0, 300)]);
    }

    /** @return array{last_tick: ?string, minutes_since: ?int, stale: bool, stale_after: int} */
    public static function scheduler(): array
    {
        $last = SystemStatus::get('scheduler.tick');
        $staleAfter = app(Settings::class)->int('automation.heartbeat_stale_minutes');
        $minutes = $last ? (int) Carbon::parse($last)->diffInMinutes(now(), true) : null;

        return ['last_tick' => $last, 'minutes_since' => $minutes, 'stale' => $minutes === null || $minutes >= $staleAfter, 'stale_after' => $staleAfter];
    }

    /** @return list<array{job: string, every_minutes: int, last_run_at: ?string, last_ok_at: ?string, last_error: ?string, overdue: bool}> */
    public static function jobs(): array
    {
        $states = SystemStatus::prefixed('job.');
        $first = SystemStatus::get('scheduler.first_tick');
        $out = [];
        foreach (self::JOBS as $job => $every) {
            $s = (array) ($states["job.$job"] ?? []);
            $out[] = [
                'job' => $job, 'every_minutes' => $every,
                'last_run_at' => $s['last_run_at'] ?? null, 'last_ok_at' => $s['last_ok_at'] ?? null,
                'last_failed_at' => $s['last_failed_at'] ?? null, 'last_error' => $s['last_error'] ?? null,
                'overdue' => self::overdue($every, $s['last_ok_at'] ?? null, $first),
            ];
        }

        return $out;
    }

    /** Kechikayotgan vazifalar haqida ogohlantirish (har biri alohida cheklanadi). */
    public static function checkJobs(): void
    {
        $settings = app(Settings::class);
        foreach (self::jobs() as $j) {
            if (! $j['overdue']) {
                continue;
            }
            if (! Cache::add('tisu.hb.job.'.$j['job'], 1, now()->addMinutes($settings->int('automation.heartbeat_alert_every_minutes')))) {
                continue;
            }
            self::alert('telegram.text.alert_job_overdue', [
                '{job}' => $j['job'],
                '{last}' => $j['last_ok_at'] ? Carbon::parse($j['last_ok_at'])->format('d.m.Y H:i') : '—',
                '{error}' => (string) ($j['last_error'] ?? ''),
            ]);
        }
    }

    /** Veb-so'rovdan keyin (5 daqiqada bir marta): cron to'xtagan bo'lsa adminlarga xabar. */
    public static function checkFromWeb(): void
    {
        try {
            if (! Cache::add('tisu.hb.webcheck', 1, now()->addMinutes(5))) {
                return;
            }
            $s = self::scheduler();
            // Hech qachon ishlamagan bo'lsa (yangi o'rnatish) — faqat holat kartasida ko'rinadi.
            if ($s['last_tick'] === null || ! $s['stale']) {
                return;
            }
            if (! Cache::add('tisu.hb.stale', 1, now()->addMinutes(app(Settings::class)->int('automation.heartbeat_alert_every_minutes')))) {
                return;
            }
            self::alert('telegram.text.alert_cron_stale', [
                '{minutes}' => (string) $s['minutes_since'],
                '{last}' => Carbon::parse($s['last_tick'])->format('d.m.Y H:i'),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function overdue(int $every, ?string $lastOk, ?string $firstTick): bool
    {
        $limit = max(2 * $every, $every + 5);
        $since = $lastOk ?? $firstTick;
        if ($since === null) {
            return false;
        }

        return Carbon::parse($since)->diffInMinutes(now(), true) > $limit;
    }

    private static function alert(string $key, array $vars): void
    {
        if (! config('services.telegram.bot_token')) {
            return;
        }
        app(BotAdmin::class)->alert(strtr((string) app(Settings::class)->get($key), $vars));
    }
}
