<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON javoblarni gzip bilan siqish: hosting proksisi (nginx → apache) API javoblarini siqmaydi,
 * /v1/public/settings kabi javoblar esa 15–20 KB. Fayl/CSV oqimlari va kichik javoblar tegilmaydi.
 */
class CompressJson
{
    private const MIN_BYTES = 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || $response->headers->has('Content-Encoding')
            || ! str_contains((string) $request->header('Accept-Encoding'), 'gzip') || ! function_exists('gzencode')) {
            return $response;
        }
        $body = (string) $response->getContent();
        if (strlen($body) < self::MIN_BYTES) {
            return $response;
        }
        $gz = gzencode($body, 5);
        if ($gz === false) {
            return $response;
        }
        $response->setContent($gz);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($gz));
        $response->setVary(array_unique(array_merge($response->getVary(), ['Accept-Encoding'])));

        return $response;
    }
}
