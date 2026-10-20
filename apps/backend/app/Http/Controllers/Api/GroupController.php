<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\ClassSession;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Audit;
use App\Support\SessionGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->with(['course:id,title', 'teacher:id,first_name,last_name', 'branch:id,name', 'schedules'])
            ->withCount('students')
            ->orderByDesc('active')->orderBy('name')
            ->get();

        return response()->json(['data' => $groups]);
    }

    public function show(Request $request, int $group): JsonResponse
    {
        $g = self::findVisible($request->user(), $group)
            ->load(['course:id,title,monthly_fee', 'teacher:id,first_name,last_name,phone', 'branch:id,name', 'schedules',
                'students' => fn ($q) => $q->select('users.id', 'first_name', 'last_name', 'phone')->orderBy('first_name')]);

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
        DB::transaction(function () use ($group, $data) {
            $group->update($data);
            if (array_key_exists('schedules', $data)) {
                $group->schedules()->delete();
                $group->schedules()->createMany($data['schedules'] ?? []);
            }
        });
        Audit::log('group.update', 'group', $group->id, $data);

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

    public function updateSession(Request $request, ClassSession $session): JsonResponse
    {
        $user = $request->user();
        self::findVisible($user, $session->group_id);
        abort_unless($user->hasPermission('group.manage') || (int) $session->group->teacher_id === (int) $user->id, 403, 'Ruxsat yo\'q.');
        $data = $request->validate([
            'topic' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cancelled' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
        ]);
        $session->update($data);

        return response()->json(['session' => $session]);
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'course_id' => [$req, 'integer', 'exists:courses,id'],
            'branch_id' => [$req, 'integer', 'exists:branches,id'],
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

    private function assertTeacher(int $id): void
    {
        $u = User::find($id);
        abort_unless($u && $u->active && $u->hasPermission('attendance.mark'), 422, 'O\'qituvchi davomat belgilash huquqiga ega bo\'lishi kerak.');
    }
}
