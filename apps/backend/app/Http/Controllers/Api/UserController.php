<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\Phone;
use App\Support\PhoneDuplicates;
use App\Support\Registration;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
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
            'pending' => ['nullable', 'boolean'],
            // Telegram holati: none — ulanmagan, blocked — botni bloklagan, linked — ishlaydigan.
            'telegram' => ['nullable', Rule::in(['none', 'blocked', 'linked'])],
            'per_page' => ['nullable', 'integer', 'between:1,500'],
        ]);
        $links = DB::table('telegram_links')->select('user_id');
        $users = User::query()
            ->when($data['role'] ?? null, fn ($q, $r) => $q->where('role', $r))
            ->when(array_key_exists('active', $data) && $data['active'] !== null, fn ($q) => $q->where('active', (bool) $data['active']))
            ->when(array_key_exists('pending', $data) && $data['pending'] !== null,
                fn ($q) => $data['pending'] ? $q->whereNotNull('pending_at') : $q->whereNull('pending_at'))
            ->when(($data['telegram'] ?? null) === 'none', fn ($q) => $q->whereNotIn('id', $links))
            ->when(($data['telegram'] ?? null) === 'blocked', fn ($q) => $q->whereIn('id', (clone $links)->whereNotNull('blocked_at')))
            ->when(($data['telegram'] ?? null) === 'linked', fn ($q) => $q->whereIn('id', (clone $links)->whereNull('blocked_at')))
            ->when($data['q'] ?? null, fn ($q, $s) => self::search($q, $s))
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? app(Settings::class)->int('ui.page_size'));
        $tg = DB::table('telegram_links')->whereIn('user_id', $users->getCollection()->pluck('id'))
            ->get(['user_id', 'username', 'blocked_at'])->keyBy('user_id');

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $u) => $u->toApi() + ['telegram' => isset($tg[$u->id])
                ? ['linked' => true, 'username' => $tg[$u->id]->username, 'blocked' => $tg[$u->id]->blocked_at !== null, 'blocked_at' => $tg[$u->id]->blocked_at]
                : ['linked' => false, 'blocked' => false]]),
            'meta' => ['total' => $users->total(), 'page' => $users->currentPage(), 'pages' => $users->lastPage(),
                'pending' => User::whereNotNull('pending_at')->count()],
        ]);
    }

    /** Arizani tasdiqlash: faol akkaunt, tanlangan rol bilan; foydalanuvchiga Telegram xabari. */
    public function approve(Request $request, Registration $registration, User $user): JsonResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(array_map(fn (Role $r) => $r->value, Registration::ROLES))]]);
        abort_unless($registration->approve($user, Role::from($data['role']), $request->user()->id), 409, "Bu ariza allaqachon ko'rib chiqilgan.");

        return response()->json(['user' => $user->fresh()->toApi()]);
    }

    public function reject(Request $request, Registration $registration, User $user): JsonResponse
    {
        abort_unless($registration->reject($user, $request->user()->id), 409, "Bu ariza allaqachon ko'rib chiqilgan.");

        return response()->json(['user' => $user->fresh()->toApi()]);
    }

    /** Takroriy akkauntlar hisoboti: bir xil normal telefon, normal bo'lmagan yozuvlar, bir xil ism-familiya. */
    public function duplicates(PhoneDuplicates $duplicates): JsonResponse
    {
        return response()->json($duplicates->report());
    }

    /**
     * Xavfsiz birlashtirish: tarixsiz (davomat, test, to'lov, guruh, sertifikat yo'q) takroriy akkaunt asosiysiga qo'shiladi —
     * Telegram ulanishi ko'chiriladi, takroriy akkaunt o'chirilmaydi, arxivlanadi (faol emas).
     */
    public function merge(Request $request, PhoneDuplicates $duplicates, User $user): JsonResponse
    {
        $data = $request->validate(['into_id' => ['required', 'integer', 'exists:users,id']]);
        $target = User::findOrFail($data['into_id']);
        abort_if($user->id === $target->id, 422, "O'zi bilan birlashtirib bo'lmaydi.");
        $this->guardRole($request->user(), $user->role, $user);
        $this->guardRole($request->user(), $target->role, $target);
        abort_if((int) $user->id === (int) $request->user()->id, 422, "O'z akkauntingizni birlashtira olmaysiz.");
        $result = $duplicates->merge($user, $target);
        Audit::log('user.merge', 'user', $target->id, ['from_id' => $user->id, 'from_phone' => $result['from_phone']] + $result['moved']);

        return response()->json(['ok' => true, 'user' => $target->fresh()->toApi()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizePhone($request);
        $data = $request->validate([
            'phone' => ['required', 'string', $this->phoneRule(), 'unique:users,phone'],
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
        $this->normalizePhone($request);
        $data = $request->validate([
            'phone' => ['sometimes', 'string', $this->phoneRule(), Rule::unique('users', 'phone')->ignore($user->id)],
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

    /** Ism, familiya ("Ali Valiyev" ham) yoki telefon (istalgan yozuvda: "90 123", "+998 90…") bo'yicha qidiruv. */
    public static function search(\Illuminate\Database\Eloquent\Builder $q, string $s): \Illuminate\Database\Eloquent\Builder
    {
        $s = trim($s);
        $digits = Phone::searchDigits($s);
        if ($digits) {
            return $q->where('phone', 'like', '%'.$digits.'%');
        }
        foreach (array_slice(preg_split('/\s+/u', $s) ?: [], 0, 3) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $q->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('phone', 'like', $like));
        }

        return $q;
    }

    /** Telefon har qanday yozuvda kelishi mumkin — validatsiya va unique tekshiruvi normal shaklda (takrorlar bo'lmasin). */
    private function normalizePhone(Request $request): void
    {
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => Phone::normalize($request->input('phone')) ?? $request->input('phone')]);
        }
    }

    private function phoneRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! is_string($value) || Phone::normalize($value) !== $value) {
                $fail(app(Settings::class)->bool('auth.allow_foreign_phones')
                    ? "Telefon raqami noto'g'ri. Masalan: +998901234567 yoki xalqaro formatda +44…"
                    : "Telefon +998XXXXXXXXX formatida bo'lishi kerak (masalan, +998901234567).");
            }
        };
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
