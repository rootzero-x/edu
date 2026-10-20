<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\ClassSession;
use App\Models\Room;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Audit;
use App\Support\ScheduleClash;
use App\Support\SessionGenerator;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class GroupController
{
    /** Foydalanuvchi ko'ra oladigan guruhlar. */
    public static function visible(User $user): Builder
    {
        $q = StudyGroup::query();
        if ($user->hasPermission('group.manage')) {
            return $q;
        }

        return $q->where(function ($w) use ($user) {
            $w->where('teacher_id', $user->id)
                ->orWhereIn('id', DB::table('group_members')->whereNull('left_at')->where('student_id', $user->id)->select('group_id'))
                ->orWhereIn('id', DB::table('group_members')->whereNull('left_at')
                    ->whereIn('student_id', DB::table('parent_child')->where('parent_id', $user->id)->select('child_id'))->select('group_id'));
        });
    }

    public static function findVisible(User $user, int $id): StudyGroup
    {
        return self::visible($user)->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $groups = self::visible($request->user())
            ->with(['course:id,title', 'teacher:id,first_name,last_name', 'schedules'])
            ->withCount('students')
            ->orderByDesc('active')->orderBy('name')
            ->get();

        return response()->json(['data' => $groups]);
    }

    public function show(Request $request, int $group): JsonResponse
    {
        // Telefon raqamlari faqat xodimlarga (o'quvchi/ota-ona sinfdoshlarining raqamini ko'rmaydi).
        $staff = $request->user()->hasPermission('attendance.mark') || $request->user()->hasPermission('group.manage');
        $teacherPhone = $staff || app(Settings::class)->bool('security.teacher_phone_visible');
        $g = self::findVisible($request->user(), $group)
            ->load(['course:id,title', 'teacher:id,first_name,last_name'.($teacherPhone ? ',phone' : ''), 'schedules',
                'students' => fn ($q) => $q->select(array_merge(['users.id', 'first_name', 'last_name'],
                    $staff ? ['phone'] : []))->orderBy('first_name')]);
        if (! $staff) {
            // A'zolik tafsilotlari (chegirma, qo'shilgan sana) — faqat xodimlarga.
            $g->students->each->makeHidden('pivot');
        }

        return response()->json(['group' => $g]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->resolveRoom($request->validate($this->rules()));
        $this->assertTeacher($data['teacher_id']);
        if ($data['active'] ?? true) {
            $this->assertNoClash($data['schedules'] ?? [], (int) $data['teacher_id'], $data['room_id'] ?? null, $data['start_date'], $data['end_date'] ?? null, null);
        }
        $group = DB::transaction(function () use ($data) {
            $g = StudyGroup::create($data);
            $g->schedules()->createMany($data['schedules'] ?? []);

            return $g;
        });
        Audit::log('group.create', 'group', $group->id, ['name' => $group->name]);

        return response()->json(['group' => $group->load('schedules')], 201);
    }

    public function update(Request $request, StudyGroup $group): JsonResponse
    {
        $data = $this->resolveRoom($request->validate($this->rules(partial: true)));
        if (isset($data['teacher_id'])) {
            $this->assertTeacher($data['teacher_id']);
        }
        // Qisman yangilashda sana oralig'i saqlangan qiymat bilan solishtiriladi.
        $start = $data['start_date'] ?? $group->start_date?->toDateString();
        $end = array_key_exists('end_date', $data) ? $data['end_date'] : $group->end_date?->toDateString();
        if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
            throw ValidationException::withMessages(['end_date' => "Tugash sanasi boshlanish sanasidan oldin bo'lishi mumkin emas."]);
        }
        // Darslar/a'zolar bor guruhning kursi o'zgarmaydi: davomat, testlar va sertifikatlar eski kursga bog'langan.
        if (isset($data['course_id']) && (int) $data['course_id'] !== (int) $group->course_id
            && (DB::table('class_sessions')->where('group_id', $group->id)->exists() || DB::table('group_members')->where('group_id', $group->id)->exists())) {
            throw ValidationException::withMessages(['course_id' => "Guruhda darslar yoki o'quvchilar bor — kursni o'zgartirib bo'lmaydi. Yangi guruh oching."]);
        }
        // Jadval, o'qituvchi, xona, sanalar yoki faollik o'zgarsa — to'qnashuv qaytadan tekshiriladi.
        $relevant = array_intersect_key($data, array_flip(['schedules', 'teacher_id', 'room_id', 'start_date', 'end_date', 'active']));
        if ($relevant && ($data['active'] ?? $group->active)) {
            $this->assertNoClash(
                $data['schedules'] ?? $group->schedules->map(fn ($s) => $s->only(['weekday', 'start_time', 'end_time']))->all(),
                (int) ($data['teacher_id'] ?? $group->teacher_id),
                array_key_exists('room_id', $data) ? $data['room_id'] : $group->room_id,
                (string) $start, $end ? (string) $end : null, $group->id,
            );
        }
        $fields = array_values(array_diff(array_keys($data), ['schedules']));
        $before = self::auditable($group, $fields, array_key_exists('schedules', $data));
        DB::transaction(function () use ($group, $data) {
            $group->update($data);
            if (array_key_exists('schedules', $data)) {
                $group->schedules()->delete();
                $group->schedules()->createMany($data['schedules'] ?? []);
                // Eski jadval bo'yicha oldindan yaratilgan, hali boshlanmagan va belgilanmagan darslar olib tashlanadi;
                // yangilari keyin jadvaldan qayta yaratiladi (sessions/generate).
                DB::table('class_sessions')->where('group_id', $group->id)->where('starts_at', '>', now())
                    ->whereNotExists(fn ($q) => $q->from('attendances')->whereColumn('attendances.session_id', 'class_sessions.id'))
                    ->delete();
            }
        });
        Audit::log('group.update', 'group', $group->id, ['before' => $before, 'after' => self::auditable($group->fresh(), $fields, array_key_exists('schedules', $data))]);

        return response()->json(['group' => $group->fresh()->load('schedules')]);
    }

    public function addMember(Request $request, StudyGroup $group): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'discount_id' => ['nullable', 'integer', 'exists:discounts,id'],
        ]);
        abort_unless(User::find($data['student_id'])->role === Role::Student, 422, 'Faqat o\'quvchini qo\'shish mumkin.');
        DB::table('group_members')->updateOrInsert(
            ['group_id' => $group->id, 'student_id' => $data['student_id']],
            ['discount_id' => $data['discount_id'] ?? null, 'joined_at' => now(), 'left_at' => null],
        );
        Audit::log('group.member_add', 'group', $group->id, $data);

        return response()->json(['ok' => true], 201);
    }

    public function removeMember(StudyGroup $group, User $student): JsonResponse
    {
        DB::table('group_members')->where(['group_id' => $group->id, 'student_id' => $student->id])->update(['left_at' => now()]);
        Audit::log('group.member_remove', 'group', $group->id, ['student_id' => $student->id]);

        return response()->json(['ok' => true]);
    }

    /** Jadvaldan darslar yaratish (takroran chaqirilsa dublikat bo'lmaydi). */
    public function generateSessions(Request $request, SessionGenerator $generator, StudyGroup $group): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $result = $generator->generate($group, $data['from'] ?? null, $data['to'] ?? null);
        Audit::log('session.generate', 'group', $group->id, $result);

        return response()->json($result);
    }

    public function sessions(Request $request, int $group): JsonResponse
    {
        $g = self::findVisible($request->user(), $group);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $sessions = $g->sessions()
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('starts_at', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('starts_at', '<', date('Y-m-d', strtotime($d.' +1 day'))))
            ->withCount('attendances as marked_count')
            ->get();

        return response()->json(['data' => $sessions]);
    }

    public function updateSession(Request $request, Settings $settings, ClassSession $session): JsonResponse
    {
        $user = $request->user();
        self::findVisible($user, $session->group_id);
        $manager = $user->hasPermission('group.manage');
        abort_unless($manager || (int) $session->group->teacher_id === (int) $user->id, 403, 'Ruxsat yo\'q.');
        $data = $request->validate([
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cancelled' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
        ]);
        // Vaqtni faqat boshqaruvchi o'zgartiradi (o'qituvchi tahrir muddatini chetlab o'tmasligi uchun).
        abort_if(! $manager && (isset($data['starts_at']) || isset($data['ends_at'])), 403, 'Dars vaqtini faqat administrator o\'zgartiradi.');
        if (array_key_exists('cancelled', $data) && ! $manager) {
            $w = AttendanceController::editWindow($settings, $session, false);
            abort_if(! $w['can_edit'] && ! $session->starts_at->isFuture(), 403, $w['message'] ?? 'Ruxsat yo\'q.');
        }
        // Frontend ISO (UTC "Z") yuboradi — ilova vaqt zonasiga o'tkazib saqlaymiz.
        foreach (['starts_at', 'ends_at'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = \Illuminate\Support\Carbon::parse($data[$k])->setTimezone(config('app.timezone'));
            }
        }
        $starts = $data['starts_at'] ?? $session->starts_at;
        $ends = $data['ends_at'] ?? $session->ends_at;
        abort_if($ends <= $starts, 422, 'Tugash vaqti boshlanishdan keyin bo\'lishi kerak.');
        if (isset($data['starts_at']) || isset($data['ends_at']) || (array_key_exists('cancelled', $data) && ! $data['cancelled'] && $session->cancelled)) {
            $clash = app(ScheduleClash::class)->forSession($session->id, (int) $session->group->teacher_id, $session->group->room_id,
                \Illuminate\Support\Carbon::parse($starts), \Illuminate\Support\Carbon::parse($ends));
            if ($clash) {
                throw ValidationException::withMessages([$clash['field'] => $clash['message']]);
            }
        }
        abort_if(isset($data['starts_at']) && ClassSession::where('group_id', $session->group_id)->where('id', '!=', $session->id)
            ->where('starts_at', $data['starts_at']->format('Y-m-d H:i:s'))->exists(), 422, 'Bu vaqtda guruhning boshqa darsi bor.');
        $session->update($data);
        Audit::log('session.update', 'session', $session->id, array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i') : $v, $data));

        return response()->json(['session' => $session->fresh()]);
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'course_id' => [$req, 'integer', 'exists:courses,id'],
            'teacher_id' => [$req, 'integer', 'exists:users,id'],
            'start_date' => [$req, 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            // Xona: room_id (xonalar ro'yxatidan). Eski mijozlar uchun erkin matn ham qabul qilinadi — xona ro'yxatga qo'shiladi.
            'room_id' => ['sometimes', 'nullable', 'integer', 'exists:rooms,id'],
            'room' => ['sometimes', 'nullable', 'string', 'max:60'],
            'active' => ['sometimes', 'boolean'],
            'schedules' => ['sometimes', 'array', 'max:14'],
            'schedules.*.weekday' => ['required', 'integer', 'between:1,7'],
            'schedules.*.start_time' => ['required', 'date_format:H:i'],
            'schedules.*.end_time' => ['required', 'date_format:H:i', 'after:schedules.*.start_time'],
        ];
    }

    /** Guruhga biriktirish mumkin bo'lgan xodimlar (davomat belgilash huquqi bor faollar). user.manage talab qilinmaydi. */
    public function teachers(): JsonResponse
    {
        $rows = User::query()->where('active', true)
            ->whereIn('role', [Role::Teacher->value, Role::Admin->value, Role::SuperAdmin->value])
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'role', 'active'])
            ->filter(fn (User $u) => $u->hasPermission('attendance.mark'))
            ->map(fn (User $u) => ['id' => $u->id, 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'role' => $u->role->value])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /** Audit uchun maydonlar qiymati (sanalar — satr, jadval — "1 14:00-15:30; ..." ko'rinishida). */
    private static function auditable(StudyGroup $g, array $fields, bool $withSchedules): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = $g->getAttribute($f);
            $out[$f] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
        }
        if ($withSchedules) {
            $out['schedules'] = $g->schedules()->get()->map(fn ($s) => $s->weekday.' '.substr($s->start_time, 0, 5).'-'.substr($s->end_time, 0, 5))->implode('; ');
        }

        return $out;
    }

    /** room_id → room (nom, bot matnlari uchun); faqat room berilgan bo'lsa — nom bo'yicha xona topiladi yoki yaratiladi. */
    private function resolveRoom(array $data): array
    {
        if (array_key_exists('room_id', $data)) {
            $data['room'] = $data['room_id'] ? Room::whereKey($data['room_id'])->value('name') : null;
        } elseif (array_key_exists('room', $data)) {
            $name = trim((string) $data['room']);
            if ($name === '') {
                $data['room'] = $data['room_id'] = null;
            } else {
                $room = Room::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?? Room::create(['name' => $name]);
                $data['room'] = $room->name;
                $data['room_id'] = $room->id;
            }
        }

        return $data;
    }

    private function assertNoClash(array $slots, int $teacherId, ?int $roomId, string $start, ?string $end, ?int $exceptGroupId): void
    {
        $clash = app(ScheduleClash::class)->forSchedule($slots, $teacherId, $roomId, $start, $end, $exceptGroupId);
        if ($clash) {
            throw ValidationException::withMessages([$clash['field'] => $clash['message']]);
        }
    }

    private function assertTeacher(int $id): void
    {
        $u = User::find($id);
        abort_unless($u && $u->active && $u->hasPermission('attendance.mark'), 422, 'O\'qituvchi davomat belgilash huquqiga ega bo\'lishi kerak.');
    }
}
