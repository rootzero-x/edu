<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\ClassSession;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Audit;
use App\Support\Enrollments;
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
                ->orWhereIn('id', DB::table('group_members')->whereNull('left_at')->where('student_id', $user->id)->select('group_id'));
        });
    }

    public static function findVisible(User $user, int $id): StudyGroup
    {
        return self::visible($user)->findOrFail($id);
    }

    public function index(Request $request): JsonResponse
    {
        $groups = self::visible($request->user())
            ->with(['course:id,title,published', 'teacher:id,first_name,last_name', 'schedules'])
            ->withCount('students')
            ->orderByDesc('active')->orderBy('name')
            ->get();

        return response()->json(['data' => $groups]);
    }

    public function show(Request $request, int $group): JsonResponse
    {
        // Telefon raqamlari faqat xodimlarga (o'quvchi sinfdoshlarining raqamini ko'rmaydi).
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
        $data = $request->validate($this->rules());
        $this->assertTeacher($data['teacher_id']);
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
        $data = $request->validate($this->rules(partial: true));
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
        Audit::log('group.update', 'group', $group->id, $data);

        return response()->json(['group' => $group->fresh()->load('schedules')]);
    }

    public function addMember(Request $request, Enrollments $enrollments, StudyGroup $group): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:users,id'],
            'discount_id' => ['nullable', 'integer', 'exists:discounts,id'],
            'start_date' => ['nullable', 'date'],
        ]);
        $result = $enrollments->join($group, User::findOrFail($data['student_id']), $data['start_date'] ?? null, $request->user()->id);
        if ($result !== 'already') {
            Audit::log('group.member_add', 'group', $group->id, $data + ['result' => $result]);
        }

        return response()->json(['ok' => true, 'result' => $result], $result === 'already' ? 200 : 201);
    }

    /** Bir nechta o'quvchini birdaniga qo'shish. Allaqachon a'zo bo'lganlar o'tkazib yuboriladi. */
    public function addMembers(Request $request, Enrollments $enrollments, StudyGroup $group): JsonResponse
    {
        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:500'],
            'student_ids.*' => ['integer', 'distinct'],
            'start_date' => ['nullable', 'date'],
        ]);
        $students = User::whereIn('id', $data['student_ids'])->get()->keyBy('id');
        $out = ['added' => [], 'already' => [], 'errors' => []];
        DB::transaction(function () use ($data, $students, $group, $enrollments, $request, &$out) {
            foreach ($data['student_ids'] as $id) {
                $student = $students[$id] ?? null;
                if (! $student || $student->role !== Role::Student) {
                    $out['errors'][] = ['student_id' => $id, 'message' => "O'quvchi topilmadi."];

                    continue;
                }
                $r = $enrollments->join($group, $student, $data['start_date'] ?? null, $request->user()->id);
                $out[$r === 'already' ? 'already' : 'added'][] = $id;
            }
        });
        Audit::log('group.member_add_bulk', 'group', $group->id, ['added' => $out['added'], 'start_date' => $data['start_date'] ?? null]);

        return response()->json(['added' => count($out['added']), 'already' => count($out['already']), 'errors' => $out['errors']]);
    }

    /** Guruhdan chiqarish (sabab bilan). Eski DELETE ham shu yerga keladi: sabab ixtiyoriy bo'lsa — bo'sh qoladi. */
    public function removeMember(Request $request, Enrollments $enrollments, StudyGroup $group, User $student): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'date'],
        ]);
        $reason = $data['reason'] ?? null;
        // Eski mijozlar (sababsiz DELETE) ishlashda davom etadi: sabab majburiy bo'lsa "Boshqa" yoziladi.
        if ($request->isMethod('delete') && $reason === null && app(Settings::class)->bool('enrollment.require_reason')) {
            $reason = (string) (collect((array) app(Settings::class)->get('enrollment.leave_reasons'))->last() ?? '—');
        }
        $enrollments->close($group, $student, Enrollments::LEFT, $data['date'] ?? null, $reason, $data['note'] ?? null, $request->user()->id);
        Audit::log('group.member_remove', 'group', $group->id, ['student_id' => $student->id, 'reason' => $reason, 'date' => $data['date'] ?? null]);

        return response()->json(['ok' => true]);
    }

    public function freezeMember(Request $request, Enrollments $enrollments, StudyGroup $group, User $student): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $r = $enrollments->freeze($group, $student, $data['from'], $data['to'], $data['reason'] ?? null, $data['note'] ?? null, $request->user()->id);
        Audit::log('group.member_freeze', 'group', $group->id, ['student_id' => $student->id] + $data + ['refunded' => $r['refunded']]);

        return response()->json(['ok' => true] + $r);
    }

    public function unfreezeMember(Request $request, Enrollments $enrollments, StudyGroup $group, User $student): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $enrollments->unfreeze($group, $student, $data['date'] ?? null, $request->user()->id);
        Audit::log('group.member_unfreeze', 'group', $group->id, ['student_id' => $student->id] + $data);

        return response()->json(['ok' => true]);
    }

    public function transferMember(Request $request, Enrollments $enrollments, StudyGroup $group, User $student): JsonResponse
    {
        $data = $request->validate([
            'to_group_id' => ['required', 'integer', 'exists:study_groups,id'],
            'date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $to = StudyGroup::findOrFail($data['to_group_id']);
        $enrollments->transfer($group, $to, $student, $data['date'] ?? null, $data['reason'] ?? null, $data['note'] ?? null, $request->user()->id);
        Audit::log('group.member_transfer', 'group', $group->id, ['student_id' => $student->id] + $data);

        return response()->json(['ok' => true]);
    }

    public function graduateMember(Request $request, Enrollments $enrollments, StudyGroup $group, User $student): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:500']]);
        $enrollments->close($group, $student, Enrollments::GRADUATED, $data['date'] ?? null, null, $data['note'] ?? null, $request->user()->id);
        Audit::log('group.member_graduate', 'group', $group->id, ['student_id' => $student->id] + $data);

        return response()->json(['ok' => true]);
    }

    /** Guruhning barcha hozirgi a'zolarini bitirgan deb belgilash (kurs tugaganda). */
    public function graduateAll(Request $request, Enrollments $enrollments, StudyGroup $group): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $n = $enrollments->graduateGroup($group, $data['date'] ?? null, $request->user()->id);
        Audit::log('group.graduate', 'group', $group->id, ['count' => $n] + $data);

        return response()->json(['graduated' => $n]);
    }

    /** A'zolar: hozirgilar holati bilan va chiqib ketganlar (sababi bilan). */
    public function members(Request $request, Enrollments $enrollments, int $group): JsonResponse
    {
        $g = self::findVisible($request->user(), $group);
        abort_unless($this->isStaff($request->user(), $g), 403, 'Ruxsat yo\'q.');
        $statuses = $enrollments->currentStatuses($g->id);
        $current = $g->students()->select('users.id', 'first_name', 'last_name', 'phone')->orderBy('first_name')->get()
            ->map(fn ($u) => ['id' => $u->id, 'first_name' => $u->first_name, 'last_name' => $u->last_name, 'phone' => $u->phone,
                'joined_at' => substr((string) $u->pivot->joined_at, 0, 10)] + ($statuses[$u->id] ?? ['status' => Enrollments::ACTIVE]));

        return response()->json(['current' => $current, 'former' => $enrollments->formerMembers($g->id)]);
    }

    /** O'quvchining shu guruhdagi tarixi. */
    public function memberHistory(Request $request, Enrollments $enrollments, int $group, User $student): JsonResponse
    {
        $g = self::findVisible($request->user(), $group);
        abort_unless($this->isStaff($request->user(), $g), 403, 'Ruxsat yo\'q.');

        return response()->json(['data' => $enrollments->timeline($student->id, [$g->id])]);
    }

    private function isStaff(User $user, StudyGroup $g): bool
    {
        return $user->hasPermission('group.manage') || ($user->hasPermission('attendance.mark') && (int) $g->teacher_id === (int) $user->id);
    }

    /** Jadvaldan darslar yaratish (takroran chaqirilsa dublikat bo'lmaydi). */
    public function generateSessions(Request $request, SessionGenerator $generator, StudyGroup $group): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json($generator->generate($group, $data['from'] ?? null, $data['to'] ?? null));
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

    /**
     * Bir nechta guruh darslari bitta so'rovda (Mini App: bugungi/yaqin darslar). Har guruhga alohida
     * so'rov yuborilsa, ko'p guruhli admin api limitiga tushib qoladi.
     */
    public function allSessions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group_ids' => ['nullable', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/'],
        ]);
        abort_if(strtotime($data['to']) - strtotime($data['from']) > 62 * 86400, 422, 'Davr 62 kundan oshmasin.');
        $ids = isset($data['group_ids']) ? array_map('intval', explode(',', $data['group_ids'])) : null;
        $groups = self::visible($request->user())->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->select('id');
        $sessions = ClassSession::whereIn('group_id', $groups)
            ->where('starts_at', '>=', $data['from'])
            ->where('starts_at', '<', date('Y-m-d', strtotime($data['to'].' +1 day')))
            ->withCount('attendances as marked_count')
            ->orderBy('starts_at')
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

    private function assertTeacher(int $id): void
    {
        $u = User::find($id);
        abort_unless($u && $u->active && $u->hasPermission('attendance.mark'), 422, 'O\'qituvchi davomat belgilash huquqiga ega bo\'lishi kerak.');
    }
}
