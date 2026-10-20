<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Production'da HTTPS'ga yo'naltirish va xavfsizlik sarlavhalari (API faqat JSON qaytaradi). */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Faqat so'rov HTTP orqali kelgani aniq bo'lsa yo'naltiramiz (proksi ortida secure() har doim ishonchli emas —
        // aks holda cheksiz yo'naltirish xavfi bor).
        if (app()->isProduction() && $request->isMethod('GET') && strtolower((string) $request->header('X-Forwarded-Proto')) === 'http') {
            return redirect()->to('https://'.$request->getHttpHost().$request->getRequestUri(), 301);
        }
        $response = $next($request);
        if (function_exists('header_remove') && ! headers_sent()) {
            header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }

        return $response;
    }
}
