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

        return response()->json([
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'version' => config('app.version'),
            'db' => $db,
            'time' => now()->toIso8601String(),
        ], $db === 'ok' ? 200 : 503);
    }
}
