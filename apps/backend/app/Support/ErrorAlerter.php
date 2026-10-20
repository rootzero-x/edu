<?php

namespace App\Support;

use App\Telegram\BotAdmin;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Server xatosi haqida admin chatiga qisqa xabar (bir xil xato — alerts.error_throttle_minutes da bir marta). */
final class ErrorAlerter
{
    private static bool $busy = false;

    public static function report(Throwable $e): void
    {
        if (self::$busy || ! config('services.telegram.bot_token')) {
            return;
        }
        self::$busy = true; // ogohlantirishning o'zi xato bersa — cheksiz sikl bo'lmasin
        try {
            $settings = app(Settings::class);
            if (! $settings->bool('alerts.errors_enabled')) {
                return;
            }
            $key = 'tisu.alert.'.sha1(get_class($e).'|'.$e->getFile().'|'.$e->getLine());
            if (! Cache::add($key, 1, now()->addMinutes($settings->int('alerts.error_throttle_minutes')))) {
                return;
            }
            $req = app()->runningInConsole() ? null : request();
            $text = strtr((string) $settings->get('telegram.text.alert_error'), [
                '{type}' => class_basename($e),
                '{message}' => mb_substr($e->getMessage(), 0, 300),
                '{where}' => str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine(),
                '{url}' => $req ? $req->method().' '.$req->path() : 'artisan '.implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 2)),
            ]);
            app(BotAdmin::class)->alert($text);
        } catch (Throwable) {
            // jim
        } finally {
            self::$busy = false;
        }
    }
}
