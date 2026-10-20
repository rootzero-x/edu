<?php

namespace App\Providers;

use App\Support\Permissions;
use App\Support\Settings;
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
        // Limitlar sozlamalardan olinadi (security.*).
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(
            // Telefon + IP: bitta akkauntga parol tanlashni cheklaydi, umumiy proksi (Vercel) ortidagi boshqa foydalanuvchilarni bloklamaydi.
            app(Settings::class)->int('security.login_per_minute'))->by(mb_strtolower((string) $r->input('phone')).'|'.$r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(
            app(Settings::class)->int('security.api_per_minute'))->by($r->user()?->id ?: $r->ip()));
    }
}
