<?php

namespace App\Providers;

use App\Support\Permissions;
use App\Support\Settings;
use App\Telegram\InitData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Settings::class);
        $this->app->scoped(Permissions::class);
        $this->app->scoped(\App\Telegram\Outbox::class);
    }

    public function boot(): void
    {
        // Tarjimalarni sozlamalardan qayta yozish (i18n.overrides: {"validation.required": "..."}).
        try {
            $overrides = (array) app(Settings::class)->get('i18n.overrides');
            $lines = [];
            foreach ($overrides as $key => $text) {
                if (is_string($key) && is_string($text) && str_contains($key, '.')) {
                    $lines[$key] = $text;
                }
            }
            if ($lines) {
                app('translator')->addLines($lines, app()->getLocale());
            }
        } catch (\Throwable) {
            // Baza hali tayyor bo'lmasa (masalan, birinchi migratsiya) standart tarjimalar ishlatiladi.
        }

        // Cron to'xtaganini cronning o'zi aniqlay olmaydi: veb-so'rovlardan keyin (5 daqiqada bir marta) tekshiriladi.
        if (! $this->app->runningInConsole()) {
            $this->app->terminating(fn () => \App\Support\Heartbeat::checkFromWeb());
        }

        // Limitlar sozlamalardan olinadi (security.*).
        RateLimiter::for('login', function (Request $r) {
            $s = app(Settings::class);
            // Normalizatsiya: "+998 90…" va "90…" bitta telefon hisoblanadi (formatni o'zgartirib limitni aylanib o'tib bo'lmaydi).
            $raw = (string) $r->input('phone');
            $phone = $raw === '' ? '' : (\App\Support\Phone::normalize($raw) ?? mb_strtolower($raw));

            // Telefon + IP: umumiy proksi (Vercel) ortidagi boshqa foydalanuvchilarni bloklamaydi.
            $limits = [Limit::perMinute($s->int('security.login_per_minute'))->by('ip:'.$phone.'|'.$r->ip())];
            if ($phone !== '') {
                // Faqat telefon bo'yicha: IP almashtirib parol tanlashni to'xtatadi (telefonsiz so'rovlarga — Telegram — tegmaydi).
                $limits[] = Limit::perHour($s->int('security.login_per_hour_per_phone'))->by('phone:'.$phone);
            }

            return $limits;
        });
        // Ochiq ariza formasi: IP bo'yicha soatiga va telefon bo'yicha kuniga (IP almashtirib spam qilishdan himoya).
        RateLimiter::for('leads', function (Request $r) {
            $s = app(Settings::class);
            $limits = [Limit::perHour($s->int('leads.public_per_hour'))->by('lead-ip:'.$r->ip())];
            $digits = preg_replace('/\D+/', '', (string) $r->input('phone'));
            if ($digits !== '') {
                $limits[] = Limit::perDay($s->int('leads.public_per_phone_per_day'))->by('lead-phone:'.substr($digits, -9));
            }

            return $limits;
        });
        // Mini App kirishi: barcha so'rovlar Next (Vercel) serveridan keladi — IP bo'yicha cheklansa, dars boshida
        // hamma o'quvchi bitta limitga tushib qoladi. Hisoblagich Telegram user id bo'yicha; IP bo'yicha — keng zaxira.
        RateLimiter::for('miniapp', function (Request $r) {
            $s = app(Settings::class);
            $tgId = InitData::unverifiedUserId((string) $r->input('init_data'));

            return [
                Limit::perMinute($s->int('security.login_per_minute'))->by($tgId ? 'tg:'.$tgId : 'tg-noid:'.$r->ip()),
                Limit::perMinute(max(300, $s->int('security.api_per_minute')))->by('tg-ip:'.$r->ip()),
            ];
        });
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(
            app(Settings::class)->int('security.api_per_minute'))->by($r->user()?->id ?: $r->ip()));
    }
}
