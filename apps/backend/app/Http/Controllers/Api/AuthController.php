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
}
