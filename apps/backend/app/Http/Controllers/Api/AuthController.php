<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function login(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:64'],
        ]);

        $user = User::where('phone', $data['phone'])->first();
        if (! $user || ! $user->active || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['phone' => __('Telefon yoki parol noto\'g\'ri.')]);
        }

        $token = $user->createToken($data['device'] ?? 'web', ['*'], now()->addDays($settings->int('auth.token_ttl_days')));

        return response()->json(['token' => $token->plainTextToken, 'user' => $user->toApi()]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $tg = \Illuminate\Support\Facades\DB::table('telegram_links')->where('user_id', $user->id)->first(['username', 'linked_at']);

        return response()->json(['user' => $user->toApi() + [
            'telegram' => $tg ? ['linked' => true, 'username' => $tg->username, 'linked_at' => $tg->linked_at] : ['linked' => false],
            'has_password' => (bool) $user->password,
        ]]);
    }

    /** O'z profilini tahrirlash (ism, familiya, til). Telefon va rol — faqat administrator. */
    public function updateMe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'min:1', 'max:64'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'locale' => ['sometimes', 'string', 'in:uz,ru,en'],
        ]);
        $request->user()->update($data);
        \App\Support\Audit::log('user.update_self', 'user', $request->user()->id, $data);

        return $this->me($request);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /** O'z parolini almashtirish: boshqa barcha sessiyalar yopiladi. */
    public function changePassword(Request $request, Settings $settings): JsonResponse
    {
        $user = $request->user();
        // Telegram orqali ro'yxatdan o'tganlarda parol yo'q — birinchi parolni joriy parolsiz o'rnatadi.
        $hasPassword = (bool) $user->password;
        $data = $request->validate([
            'current_password' => [$hasPassword ? 'required' : 'nullable', 'string'],
            'new_password' => ['required', 'string', 'min:'.$settings->int('auth.min_password_length'), 'different:current_password'],
        ]);
        if ($hasPassword && ! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('Joriy parol noto\'g\'ri.')]);
        }
        $user->update(['password' => $data['new_password']]);
        $current = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $current)->delete();

        return response()->json(['ok' => true]);
    }
}
