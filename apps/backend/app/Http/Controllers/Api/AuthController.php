<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function login(Request $request): JsonResponse
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

        $token = $user->createToken($data['device'] ?? 'web', ['*'], now()->addDays(30));

        return response()->json(['token' => $token->plainTextToken, 'user' => $user->toApi()]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->toApi()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }

    /** O'z parolini almashtirish: boshqa barcha sessiyalar yopiladi. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:10', 'different:current_password'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('Joriy parol noto\'g\'ri.')]);
        }
        $user->update(['password' => $data['new_password']]);
        $current = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $current)->delete();

        return response()->json(['ok' => true]);
    }
}
