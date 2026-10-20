<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Settings;
use App\Telegram\BotHandler;
use App\Telegram\InitData;
use App\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TelegramAuthController
{
    /** 1) Sayt: kirish so'rovini boshlash → bot havolasi. */
    public function start(Request $request, Settings $settings): JsonResponse
    {
        $bot = ltrim((string) $settings->get('telegram.bot_username'), '@');
        abort_if($bot === '', 503, 'Telegram bot sozlanmagan.');
        DB::table('telegram_login_requests')->where('expires_at', '<', now()->subDay())->delete();

        $code = Str::random(32);
        $secret = Str::random(48);
        $ttl = $settings->int('telegram.login_code_ttl_seconds');
        DB::table('telegram_login_requests')->insert([
            'code' => $code, 'poll_secret' => hash('sha256', $secret), 'status' => 'PENDING',
            'ip' => $request->ip(), 'expires_at' => now()->addSeconds($ttl), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json([
            'code' => $code,
            'poll_secret' => $secret,
            'bot_url' => "https://t.me/$bot?start=login_$code",
            'expires_in' => $ttl,
        ]);
    }

    /** 2) Sayt: bot tasdiqlaganini tekshirish. Tasdiqlangan bo'lsa token bir marta beriladi. */
    public function poll(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:32'], 'poll_secret' => ['required', 'string']]);
        $row = DB::table('telegram_login_requests')->where('code', $data['code'])->first();
        if (! $row || ! hash_equals($row->poll_secret, hash('sha256', $data['poll_secret']))) {
            abort(404);
        }
        if ($row->status === 'PENDING') {
            return response()->json(['status' => now()->greaterThan($row->expires_at) ? 'expired' : 'pending']);
        }
        $consumed = DB::table('telegram_login_requests')->where('code', $row->code)->where('status', 'CONFIRMED')
            ->update(['status' => 'CONSUMED', 'updated_at' => now()]);
        if (! $consumed) {
            return response()->json(['status' => 'expired']);
        }

        return $this->issue(User::findOrFail($row->user_id), 'telegram-web', $settings);
    }

    /** Mini App: initData imzosi bilan kirish. */
    public function miniApp(Request $request, Settings $settings, TelegramClient $tg): JsonResponse
    {
        $data = $request->validate(['init_data' => ['required', 'string', 'max:8192']]);
        $fields = InitData::verify($data['init_data'], $tg->token(), $settings->int('telegram.miniapp_auth_max_age_seconds'));
        abort_unless($fields, 401, 'initData noto\'g\'ri yoki eskirgan.');

        $userId = DB::table('telegram_links')->where('telegram_id', $fields['user']['id'])->value('user_id');
        if (! $userId) {
            return response()->json(['status' => 'need_phone', 'message' => 'Avval botga telefon raqamingizni yuboring.'], 404);
        }

        return $this->issue(User::findOrFail($userId), 'telegram-miniapp', $settings);
    }

    /** Telegram webhook. Har doim 200 qaytaradi, aks holda Telegram qayta-qayta yuboradi. */
    public function webhook(Request $request, BotHandler $bot): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        try {
            $bot->handle($request->all());
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }

    private function issue(User $user, string $device, Settings $settings): JsonResponse
    {
        abort_unless($user->active, 403, 'Akkaunt faol emas.');
        $token = $user->createToken($device, ['*'], now()->addDays($settings->int('auth.token_ttl_days')));

        return response()->json(['status' => 'ok', 'token' => $token->plainTextToken, 'user' => $user->toApi()]);
    }
}
