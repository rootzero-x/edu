<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Tizim holati (system_status jadvali): cron yurak urishi, vazifalar natijasi, oxirgi zaxira. Kesh emas — server qayta ishga tushsa ham saqlanadi. */
final class SystemStatus
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $v = DB::table('system_status')->where('key', $key)->value('value');
        } catch (Throwable) {
            return $default;
        }

        return $v === null ? $default : json_decode((string) $v, true);
    }

    public static function put(string $key, mixed $value): void
    {
        DB::table('system_status')->updateOrInsert(['key' => $key], [
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_at' => now(),
        ]);
    }

    /** Massiv qiymatni qisman yangilash. */
    public static function merge(string $key, array $patch): void
    {
        self::put($key, $patch + (array) self::get($key, []));
    }

    /** @return array<string, mixed> prefiks bilan boshlanadigan kalitlar */
    public static function prefixed(string $prefix): array
    {
        return DB::table('system_status')->where('key', 'like', $prefix.'%')->pluck('value', 'key')
            ->map(fn ($v) => json_decode((string) $v, true))->all();
    }
}
