<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use App\Support\UploadTickets;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yuklash chiptasi (UploadTickets) faqat o'z yo'liga POST uchun ishlaydi; fayl qabul qilingach o'chiriladi.
 *
 * Bo'lakli yuklash: hostingda upload_max_filesize = 2M, shuning uchun katta fayl brauzerda ~1,5 MB li bo'laklarga
 * bo'linib, xuddi shu manzilga X-Upload-Id / X-Chunk-Index / X-Chunk-Total sarlavhalari bilan yuboriladi.
 * Oraliq bo'laklar vaqtincha saqlanadi (202), oxirgisida fayl yig'ilib "file" sifatida controllerga uzatiladi.
 */
class RestrictUploadTicket
{
    private const MAX_CHUNKS = 200;

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        if (! $token || $token->name !== UploadTickets::TOKEN_NAME) {
            return $next($request);
        }
        $path = (string) preg_replace('#^v1/#', '', $request->path());
        if (! $request->isMethod('POST') || ! $token->can('upload:'.$path) || ($token->expires_at && $token->expires_at->isPast())) {
            abort(403, "Yuklash chiptasi bu so'rov uchun emas yoki muddati o'tgan.");
        }

        $dir = null;
        if ($request->hasHeader('X-Chunk-Total')) {
            [$dir, $done] = $this->chunk($request, (int) $token->id);
            if (! $done) {
                return response()->json(['ok' => true, 'chunk' => (int) $request->header('X-Chunk-Index')], 202);
            }
        }
        try {
            return $next($request);
        } finally {
            $token->delete();
            Auth::forgetGuards();
            if ($dir) {
                $this->cleanup($dir);
            }
        }
    }

    /** @return array{0: string, 1: bool} bo'laklar papkasi va fayl to'liq yig'ildimi */
    private function chunk(Request $request, int $tokenId): array
    {
        $id = (string) $request->header('X-Upload-Id');
        $index = (int) $request->header('X-Chunk-Index');
        $total = (int) $request->header('X-Chunk-Total');
        $part = $request->file('chunk');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) && $total >= 1 && $total <= self::MAX_CHUNKS
            && $index >= 0 && $index < $total && $part instanceof UploadedFile && $part->isValid(), 422, "Fayl bo'lagi noto'g'ri.");

        $maxMb = app(Settings::class)->int('uploads.max_mb');
        $dir = storage_path("app/chunks/{$tokenId}-{$id}");
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $part->move($dir, sprintf('%04d', $index));
        $parts = glob($dir.'/[0-9][0-9][0-9][0-9]') ?: [];
        if (array_sum(array_map('filesize', $parts)) > $maxMb * 1024 * 1024) {
            $this->cleanup($dir);
            abort(422, "Fayl hajmi $maxMb MB dan oshmasligi kerak.");
        }
        if (count($parts) < $total) {
            return [$dir, false];
        }

        // Hammasi keldi — tartib bilan yig'amiz va so'rovga oddiy "file" sifatida qo'yamiz.
        $assembled = $dir.'/assembled';
        $out = fopen($assembled, 'wb');
        for ($i = 0; $i < $total; $i++) {
            $p = $dir.'/'.sprintf('%04d', $i);
            if (! is_file($p)) {
                fclose($out);
                $this->cleanup($dir);
                abort(422, "Fayl bo'laklari to'liq emas.");
            }
            $in = fopen($p, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);
        $name = mb_substr((string) ($request->input('filename') ?: 'file'), 0, 200);
        // "chunk" fayli allaqachon ko'chirilgan (vaqtinchalik fayl yo'q) — so'rovdan olib tashlanadi, aks holda
        // Laravel fayllarni qayta o'girishda "fayl mavjud emas" xatosini beradi.
        $request->files->remove('chunk');
        $request->files->set('file', new UploadedFile($assembled, $name, null, null, true));
        // Laravel fayllar ro'yxatini keshlaydi ("chunk" o'qilganda) — yangi "file" ko'rinishi uchun kesh tozalanadi.
        (fn () => $this->convertedFiles = null)->call($request);

        return [$dir, true];
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
