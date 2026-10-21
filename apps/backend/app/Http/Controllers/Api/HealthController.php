<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            $db = 'ok';
        } catch (Throwable $e) {
            report($e);
            $db = 'down';
        }

        // Cron holati (tashqi monitoring uchun): vaqt va kechikish daqiqalari, sir ma'lumot yo'q.
        $scheduler = $db === 'ok' ? \App\Support\Heartbeat::scheduler() : null;

        return response()->json([
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'cron' => $scheduler === null ? null : ($scheduler['last_tick'] === null ? 'never' : ($scheduler['stale'] ? 'stale' : 'ok')),
            'db' => $db,
            'scheduler' => $scheduler ? ['minutes_since' => $scheduler['minutes_since'], 'stale' => $scheduler['stale']] : null,
            'time' => now()->toIso8601String(),
        ], $db === 'ok' ? 200 : 503);
    }
}
