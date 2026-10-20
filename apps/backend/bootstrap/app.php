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
        // Faqat serverning o'z proksisiga (nginx → apache) ishonamiz; aks holda X-Forwarded-For soxtalashtirilib,
        // IP bo'yicha limitlarni chetlab o'tish mumkin. Ro'yxat .env: TRUSTED_PROXIES="127.0.0.1,::1".
        $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1'))))));
        // API: mehmonni login sahifasiga yo'naltirmaslik, 401 JSON qaytarish.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->alias(['perm' => \App\Http\Middleware\RequirePermission::class]);
        // Huquq tekshiruvi model binding'dan oldin: ruxsati yo'q foydalanuvchi 404/403 farqidan ID mavjudligini bilolmaydi.
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, \App\Http\Middleware\RequirePermission::class);
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => true);
        // Haqiqiy server xatolari (404/403/validatsiya emas) — admin chatiga ogohlantirish.
        $exceptions->report(fn (\Throwable $e) => \App\Support\ErrorAlerter::report($e));
        // Foydalanuvchiga tushunarli (tarjima qilinadigan) xabarlar; ichki tafsilotlar ko'rsatilmaydi.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            // abort(404, '...') matni saqlanadi; Laravel'ning standart inglizcha xabarlari o'rniga — tarjima.
            $m = $e->getMessage();
            $generic = $m === '' || str_starts_with($m, 'The route') || str_starts_with($m, 'No query results');
            // Yo'q (o'chirilgan) yozuv: nima topilmaganini aytamiz — "Topilmadi." o'rniga (Mini App, sayt, bot).
            $prev = $e->getPrevious();
            if ($generic && $prev instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $entity = class_basename($prev->getModel());
                $text = [
                    'Course' => "Kurs topilmadi — u o'chirilgan bo'lishi mumkin.",
                    'Lesson' => "Dars topilmadi — u o'chirilgan bo'lishi mumkin. Kurs sahifasiga qayting.",
                    'Quiz' => "Test topilmadi — u o'chirilgan bo'lishi mumkin.",
                    'QuizAttempt' => 'Urinish topilmadi. Test sahifasini qayta oching.',
                    'ClassSession' => "Dars (mashg'ulot) topilmadi — u o'chirilgan bo'lishi mumkin.",
                    'StudyGroup' => "Guruh topilmadi yoki u sizga tegishli emas (guruhdan chiqqan bo'lishingiz mumkin).",
                    'Announcement' => "Yangilik topilmadi — u o'chirilgan bo'lishi mumkin.",
                    'Certificate' => 'Sertifikat topilmadi yoki bekor qilingan.',
                    'User' => 'Foydalanuvchi topilmadi.',
                ][$entity] ?? null;
                if ($text) {
                    return response()->json(['message' => __($text), 'code' => 'NOT_FOUND', 'entity' => $entity], 404);
                }
            }

            return response()->json(['message' => $generic ? __('Topilmadi.') : $m], 404);
        });
        $exceptions->render(fn (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $e) => response()->json(['message' => __('Bu amal qo\'llab-quvvatlanmaydi.')], 405));
    })->create();
