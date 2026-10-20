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
            'first_name' => ['required', 'string', 'max:64'],
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
            'first_name' => ['sometimes', 'string', 'max:64'],
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

        $user->update($data);
        if (isset($data['password']) || ($data['active'] ?? true) === false) {
            $user->tokens()->delete();
        }
        Audit::log('user.update', 'user', $user->id, array_diff_key($data, ['password' => 1]) + (isset($data['password']) ? ['password' => 'reset'] : []));

        return response()->json(['user' => $user->fresh()->toApi()]);
    }

    /** Ota-onaning bog'langan farzandlari. */
    public function children(User $parent): JsonResponse
    {
        $ids = \Illuminate\Support\Facades\DB::table('parent_child')->where('parent_id', $parent->id)->pluck('child_id');

        return response()->json(['data' => User::whereIn('id', $ids)->orderBy('first_name')->get()->map->toApi()]);
    }

    /** Ota-onani farzandga bog'lash (davomat xabarlari va ko'rish huquqi uchun). */
    public function linkChild(Request $request, User $parent): JsonResponse
    {
        $data = $request->validate(['child_id' => ['required', 'integer', 'exists:users,id']]);
        abort_unless($parent->role === Role::Parent, 422, 'Foydalanuvchi ota-ona roliga ega emas.');
        abort_unless(User::find($data['child_id'])->role === Role::Student, 422, 'Farzand o\'quvchi bo\'lishi kerak.');
        \Illuminate\Support\Facades\DB::table('parent_child')->insertOrIgnore(['parent_id' => $parent->id, 'child_id' => $data['child_id']]);
        Audit::log('user.link_child', 'user', $parent->id, $data);

        return response()->json(['ok' => true], 201);
    }

    public function unlinkChild(User $parent, User $child): JsonResponse
    {
        \Illuminate\Support\Facades\DB::table('parent_child')->where(['parent_id' => $parent->id, 'child_id' => $child->id])->delete();
        Audit::log('user.unlink_child', 'user', $parent->id, ['child_id' => $child->id]);

        return response()->json(['ok' => true]);
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
