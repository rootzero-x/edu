<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        // API: mehmonni login sahifasiga yo'naltirmaslik, 401 JSON qaytarish.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias(['perm' => \App\Http\Middleware\RequirePermission::class]);
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => true);
        // Foydalanuvchiga tushunarli (tarjima qilinadigan) xabarlar; ichki tafsilotlar ko'rsatilmaydi.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            // abort(404, '...') matni saqlanadi; Laravel'ning standart inglizcha xabarlari o'rniga — tarjima.
            $m = $e->getMessage();
            $generic = $m === '' || str_starts_with($m, 'The route') || str_starts_with($m, 'No query results');

            return response()->json(['message' => $generic ? __('Topilmadi.') : $m], 404);
        });
        $exceptions->render(fn (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e) => response()->json(['message' => __('Bu amal qo\'llab-quvvatlanmaydi.')], 405));
    })->create();
