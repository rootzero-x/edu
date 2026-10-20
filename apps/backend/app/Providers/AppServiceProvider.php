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

        // Limitlar sozlamalardan olinadi (security.*).
        RateLimiter::for('login', function (Request $r) {
            $s = app(Settings::class);
            $phone = mb_strtolower((string) $r->input('phone'));

            // Telefon + IP: umumiy proksi (Vercel) ortidagi boshqa foydalanuvchilarni bloklamaydi.
            $limits = [Limit::perMinute($s->int('security.login_per_minute'))->by('ip:'.$phone.'|'.$r->ip())];
            if ($phone !== '') {
                // Faqat telefon bo'yicha: IP almashtirib parol tanlashni to'xtatadi (telefonsiz so'rovlarga — Telegram — tegmaydi).
                $limits[] = Limit::perHour($s->int('security.login_per_hour_per_phone'))->by('phone:'.$phone);
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
