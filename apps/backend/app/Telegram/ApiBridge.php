<?php

namespace App\Telegram;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Bot API controller'larini foydalanuvchi nomidan chaqiradi — huquqlar, ko'rinish doirasi,
 * validatsiya va audit sayt/Mini App bilan aynan bir xil ishlaydi (logika takrorlanmaydi).
 */
class ApiBridge
{
    public function __construct(private Container $app, private Texts $texts) {}

    /**
     * @param array{0: class-string, 1: string} $action controller va metod
     * @param array $params metodga beriladigan nomli parametrlar (masalan, model)
     * @param string|null $permission route middleware'dagi huquq (perm:...)
     */
    public function call(User $user, array $action, array $params = [], array $input = [], ?string $permission = null): array
    {
        if (! $user->active) {
            throw new BotError($this->texts->get('telegram.text.account_blocked'));
        }
        if ($permission && ! $user->hasPermission($permission)) {
            throw new BotError($this->texts->get('telegram.text.no_permission'));
        }
        $request = Request::create('/telegram-bot', 'POST', $input);
        $request->headers->set('Accept', 'application/json');
        $previous = $this->app->make('request');
        $this->app->instance('request', $request);
        // instance() auth'ning "rebinding" callback'ini ishga tushiradi — resolver undan keyin o'rnatiladi.
        $request->setUserResolver(fn () => $user);
        try {
            $response = $this->app->call([$this->app->make($action[0]), $action[1]], $params);

            return $response instanceof JsonResponse ? (array) $response->getData(true) : [];
        } catch (ValidationException $e) {
            throw new BotError(implode(' ', Arr::flatten($e->errors())));
        } catch (HttpExceptionInterface $e) {
            throw new BotError($e->getMessage() !== '' ? $e->getMessage() : $this->texts->get('telegram.text.no_permission'));
        } catch (ModelNotFoundException) {
            throw new BotError($this->texts->get('telegram.text.no_permission'));
        } finally {
            $this->app->instance('request', $previous);
        }
    }
}
