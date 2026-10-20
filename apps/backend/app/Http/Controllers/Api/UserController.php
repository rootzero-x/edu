<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class UserController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'role' => ['nullable', new Enum(Role::class)],
            'q' => ['nullable', 'string', 'max:64'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,500'],
        ]);
        $users = User::query()
            ->when($data['role'] ?? null, fn ($q, $r) => $q->where('role', $r))
            ->when(array_key_exists('active', $data) && $data['active'] !== null, fn ($q) => $q->where('active', (bool) $data['active']))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('phone', 'like', "%$s%")->orWhere('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")))
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? app(Settings::class)->int('ui.page_size'));

        return response()->json([
            'data' => $users->getCollection()->map->toApi(),
            'meta' => ['total' => $users->total(), 'page' => $users->currentPage(), 'pages' => $users->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+998\d{9}$/', 'unique:users,phone'],
            'first_name' => ['bail', 'required', 'string', 'max:64'],
            'last_name' => ['nullable', 'string', 'max:64'],
            'role' => ['required', new Enum(Role::class)],
            'password' => ['nullable', 'string', 'min:'.app(Settings::class)->int('auth.min_password_length')],
        ]);
        $this->guardRole($request->user(), Role::from($data['role']));

        $user = User::create($data)->fresh();
        Audit::log('user.create', 'user', $user->id, ['role' => $data['role'], 'phone' => $data['phone']]);

        return response()->json(['user' => $user->toApi()], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['sometimes', 'string', 'regex:/^\+998\d{9}$/', Rule::unique('users', 'phone')->ignore($user->id)],
            'first_name' => ['sometimes', 'bail', 'required', 'string', 'max:64'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'role' => ['sometimes', new Enum(Role::class)],
            'active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', 'min:'.app(Settings::class)->int('auth.min_password_length')],
        ]);
        $actor = $request->user();
        $this->guardRole($actor, $user->role, $user);
        if (isset($data['role'])) {
            $this->guardRole($actor, Role::from($data['role']), $user);
        }
        if ((int) $user->id === (int) $actor->id && (($data['active'] ?? true) === false || isset($data['role']))) {
            abort(422, 'O\'zingizning rolingiz yoki holatingizni o\'zgartira olmaysiz.');
        }
        $roleChanged = isset($data['role']) && $data['role'] !== $user->role->value;
        // Faol guruh o'qituvchisi davomat huquqisiz rolga o'tkazilmaydi — aks holda guruh o'qituvchisiz qoladi.
        if ($roleChanged && ! app(\App\Support\Permissions::class)->has(Role::from($data['role']), 'attendance.mark')
            && \App\Models\StudyGroup::where('teacher_id', $user->id)->where('active', true)->exists()) {
            throw ValidationException::withMessages(['role' => 'Bu foydalanuvchi faol guruh o\'qituvchisi. Avval guruhlarga boshqa o\'qituvchi tayinlang.']);
        }
        // Guruh a'zosi bo'lgan o'quvchi boshqa rolga o'tkazilmaydi (davomat, testlar, xabarlar shunga bog'liq).
        if ($roleChanged && $user->role === Role::Student
            && \Illuminate\Support\Facades\DB::table('group_members')->where('student_id', $user->id)->whereNull('left_at')->exists()) {
            throw ValidationException::withMessages(['role' => 'Bu o\'quvchi guruh a\'zosi. Avval uni guruhlardan chiqaring.']);
        }

        $user->update($data);
        // Rol o'zgarsa eski tokenlar (va ulardagi huquqlar) bekor qilinadi — qayta kirish talab etiladi.
        if (isset($data['password']) || ($data['active'] ?? true) === false || $roleChanged) {
            $user->tokens()->delete();
        }
        Audit::log('user.update', 'user', $user->id, array_diff_key($data, ['password' => 1]) + (isset($data['password']) ? ['password' => 'reset'] : []));

        return response()->json(['user' => $user->fresh()->toApi()]);
    }

    /**
     * Administratorlar faqat SUPER_ADMIN tomonidan yaratiladi/o'zgartiriladi: oddiy ADMIN boshqa
     * admin akkauntini (telefon/parol/holat) o'zgartirib egallab ololmaydi va admin tayinlay olmaydi.
     * O'z akkauntini (ism, parol) tahrirlash mumkin.
     */
    private function guardRole(User $actor, Role $target, ?User $subject = null): void
    {
        if ($actor->role === Role::SuperAdmin) {
            return;
        }
        $self = $subject && (int) $subject->id === (int) $actor->id;
        if (in_array($target, [Role::Admin, Role::SuperAdmin], true) && ! $self) {
            abort(403, 'Administratorlarni faqat bosh administrator boshqaradi.');
        }
    }
}
