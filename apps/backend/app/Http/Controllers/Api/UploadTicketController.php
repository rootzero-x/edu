<?php

namespace App\Http\Controllers\Api;

use App\Support\UploadTickets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadTicketController
{
    /** Bir martalik yuklash chiptasi: brauzer faylni shu manzilga shu token bilan bevosita yuboradi. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:200']]);
        $path = ltrim($data['path'], '/');
        abort_unless(UploadTickets::allowed($path), 404, 'Topilmadi.');
        $user = $request->user();
        // Eskirgan chiptalar yig'ilib qolmasin.
        $user->tokens()->where('name', UploadTickets::TOKEN_NAME)->where('expires_at', '<', now())->delete();
        // Tashlab ketilgan bo'lakli yuklashlar (chipta 10 daqiqa) — bir kundan eski papkalar o'chiriladi.
        foreach (glob(storage_path('app/chunks/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < now()->subDay()->getTimestamp()) {
                array_map('unlink', glob($dir.'/*') ?: []);
                @rmdir($dir);
            }
        }
        $expires = now()->addMinutes(10);
        $token = $user->createToken(UploadTickets::TOKEN_NAME, ['upload:'.$path], $expires);

        return response()->json([
            'url' => rtrim((string) config('app.url'), '/').'/v1/'.$path,
            'token' => $token->plainTextToken,
            'expires_at' => $expires->toIso8601String(),
        ]);
    }
}
