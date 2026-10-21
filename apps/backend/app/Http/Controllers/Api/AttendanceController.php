<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance;
use App\Models\ClassSession;
use App\Support\Audit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController
{
    /** Dars davomat varaqasi: guruhdagi hozirgi o'quvchilar + belgilangan holatlar. */
    public function sheet(Request $request, ClassSession $session): JsonResponse
    {
        $group = GroupController::findVisible($request->user(), $session->group_id);
        $records = DB::table('attendances')->where('session_id', $session->id)->get()->keyBy('student_id');
        $students = self::roster($group->id, $session, $records->keys()->all());
        $canSeeAll = $request->user()->hasPermission('attendance.mark');

        $rows = $students->filter(fn ($s) => $canSeeAll || (int) $s->id === (int) $request->user()->id)
            ->map(fn ($s) => [
                'student_id' => $s->id,
                'name' => trim($s->first_name.' '.$s->last_name),
                'status' => $records[$s->id]->status ?? null,
                'arrived_at' => $records[$s->id]->arrived_at ?? null,
                'note' => $records[$s->id]->note ?? null,
                // Guruhdan chiqib ketgan, lekin dars paytida a'zo bo'lgan (davomatini tuzatish mumkin).
                'left' => $s->left_at !== null,
                'left_at' => $s->left_at,
            ])->values();

        $admin = $request->user()->hasPermission('group.manage');
        $isTeacher = $admin || (int) $group->teacher_id === (int) $request->user()->id;

        return response()->json([
            'session' => $session, 'group' => ['id' => $group->id, 'name' => $group->name], 'rows' => $rows,
            'edit' => $isTeacher && $request->user()->hasPermission('attendance.mark')
                ? self::editWindow(app(Settings::class), $session, $admin) : ['can_edit' => false, 'reason_required' => false],
        ]);
    }

    /** Davomatni saqlash. status berilmasa arrived_at bo'yicha sozlamalardagi qoidalar bilan aniqlanadi. */
    public function mark(Request $request, Settings $settings, ClassSession $session): JsonResponse
    {
        $user = $request->user();
        $group = GroupController::findVisible($user, $session->group_id);
        $admin = $user->hasPermission('group.manage');
        abort_unless($admin || (int) $group->teacher_id === (int) $user->id, 403, 'Faqat guruh o\'qituvchisi belgilay oladi.');
        abort_if($session->cancelled, 422, 'Dars bekor qilingan.');
        abort_if($session->starts_at->isFuture(), 422, 'Dars hali boshlanmagan.');
        $window = self::editWindow($settings, $session, $admin);
        abort_unless($window['can_edit'], 403, $window['message'] ?? 'Ruxsat yo\'q.');

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.student_id' => ['required', 'integer'],
            'items.*.status' => ['nullable', Rule::in([Attendance::PRESENT, Attendance::LATE, Attendance::ABSENT, Attendance::EXCUSED])],
            'items.*.arrived_at' => ['nullable', 'date_format:H:i'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'reason' => [$window['reason_required'] ? 'required' : 'nullable', 'string', 'max:255'],
        ]);
        $memberIds = self::roster($group->id, $session, DB::table('attendances')->where('session_id', $session->id)->pluck('student_id')->all())
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $before = DB::table('attendances')->where('session_id', $session->id)->pluck('status', 'student_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => $v]);
        $grace = $settings->int('attendance.grace_minutes');
        $absentAfter = $settings->int('attendance.absent_after_minutes');

        $changed = [];
        DB::transaction(function () use ($data, $memberIds, $session, $user, $grace, $absentAfter, $before, &$changed) {
            foreach ($data['items'] as $item) {
                $sid = (int) $item['student_id'];
                abort_unless(in_array($sid, $memberIds, true), 422, "O'quvchi #$sid bu guruhda emas.");
                $arrived = isset($item['arrived_at'])
                    ? CarbonImmutable::parse($session->starts_at->format('Y-m-d').' '.$item['arrived_at'])
                    : null;
                $status = $item['status'] ?? ($arrived ? Attendance::classifyArrival($session->starts_at, $arrived, $grace, $absentAfter) : null);
                abort_unless($status, 422, "O'quvchi #$sid: status yoki kelgan vaqt kerak.");
                DB::table('attendances')->updateOrInsert(
                    ['session_id' => $session->id, 'student_id' => $sid],
                    ['status' => $status, 'arrived_at' => $arrived, 'note' => $item['note'] ?? null, 'marked_by' => $user->id, 'marked_at' => now()],
                );
                if (($before[$sid] ?? null) !== $status) {
                    $changed[$sid] = $status;
                }
            }
        });
        if ($changed) {
            Audit::log('attendance.mark', 'session', $session->id, [
                'changes' => array_map(fn ($sid, $to) => ['student_id' => $sid, 'from' => $before[$sid] ?? null, 'to' => $to], array_keys($changed), $changed),
                'reason' => $data['reason'] ?? null,
            ]);
        }
        return response()->json(['ok' => true, 'changed' => count($changed)]);
    }

    /** Guruh bo'yicha davomat foizi (o'tgan, bekor qilinmagan darslar). */
    public function report(Request $request, int $group): JsonResponse
    {
        $g = GroupController::findVisible($request->user(), $group);

        return response()->json(['data' => $this->reportRows($request->user(), [$g->id])[$g->id] ?? []]);
    }

    /**
     * Bir nechta guruh davomati bitta so'rovda (Mini App: admin/o'qituvchining barcha guruhlari).
     * group_ids bo'lmasa — ko'rinadigan faol guruhlar. Har guruhga alohida so'rov ko'p guruhli adminni sekinlashtirardi.
     */
    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate(['group_ids' => ['nullable', 'string', 'max:2000', 'regex:/^\d+(,\d+)*$/']]);
        $ids = isset($data['group_ids']) ? array_map('intval', explode(',', $data['group_ids'])) : null;
        $groupIds = GroupController::visible($request->user())
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->where('active', true))
            ->orderBy('name')->limit(200)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rows = $this->reportRows($request->user(), $groupIds);

        return response()->json(['data' => array_map(fn ($id) => ['group_id' => $id, 'rows' => $rows[$id] ?? []], $groupIds)]);
    }

    /**
     * Guruhlar bo'yicha o'quvchilar davomati: hozirgi a'zolar + davomati bor chiqib ketganlar (tarix yo'qolmasin, belgi bilan).
     * Xodim — hamma o'quvchini, o'quvchi — faqat o'zini ko'radi. Barcha guruhlar uchun 2 ta so'rov.
     *
     * @param  list<int>  $groupIds
     * @return array<int, list<array>>
     */
    private function reportRows(\App\Models\User $user, array $groupIds): array
    {
        if (! $groupIds) {
            return [];
        }
        $canSeeAll = $user->hasPermission('attendance.mark');
        // Har bir belgi PHP ga yuklanmaydi — SQL da sanaladi (guruh × o'quvchi × holat). O'quvchi faqat o'zinikini.
        $marks = DB::table('attendances')
            ->join('class_sessions', 'class_sessions.id', '=', 'attendances.session_id')
            ->whereIn('class_sessions.group_id', $groupIds)->where('class_sessions.cancelled', false)
            ->when(! $canSeeAll, fn ($q) => $q->where('attendances.student_id', $user->id))
            ->groupBy('class_sessions.group_id', 'attendances.student_id', 'attendances.status')
            ->selectRaw('class_sessions.group_id, attendances.student_id, attendances.status, count(*) as c')
            ->get()
            ->groupBy(fn ($r) => $r->group_id.':'.$r->student_id);
        $members = DB::table('group_members')->join('users', 'users.id', '=', 'group_members.student_id')
            ->whereIn('group_members.group_id', $groupIds)
            ->when(! $canSeeAll, fn ($q) => $q->where('group_members.student_id', $user->id))
            ->orderBy('users.first_name')
            ->get(['group_members.group_id', 'users.id', 'users.first_name', 'users.last_name', 'group_members.left_at']);

        $out = [];
        foreach ($members as $s) {
            $statuses = [];
            foreach ($marks[$s->group_id.':'.$s->id] ?? [] as $m) {
                array_push($statuses, ...array_fill(0, (int) $m->c, $m->status));
            }
            if ($s->left_at !== null && ! $statuses) {
                continue;
            }
            $out[(int) $s->group_id][] = [
                'student_id' => $s->id,
                'name' => trim($s->first_name.' '.$s->last_name),
                'counts' => array_count_values($statuses) + ['PRESENT' => 0, 'LATE' => 0, 'ABSENT' => 0, 'EXCUSED' => 0],
                'rate' => Attendance::rate($statuses),
                'left' => $s->left_at !== null,
                'left_at' => $s->left_at,
            ];
        }

        return $out;
    }

    /** O'zgarishlar tarixi (audit_logs dan). */
    public function history(Request $request, ClassSession $session): JsonResponse
    {
        GroupController::findVisible($request->user(), $session->group_id);
        abort_unless($request->user()->hasPermission('attendance.mark'), 403, 'Ruxsat yo\'q.');
        $names = [];
        $rows = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->where(['audit_logs.entity' => 'session', 'audit_logs.entity_id' => $session->id, 'audit_logs.action' => 'attendance.mark'])
            ->orderByDesc('audit_logs.id')->limit(100)
            ->get(['audit_logs.id', 'audit_logs.data', 'audit_logs.created_at', 'users.first_name', 'users.last_name'])
            ->map(function ($r) use (&$names) {
                $d = json_decode((string) $r->data, true) ?: [];
                $changes = $d['changes'] ?? array_map(fn ($sid, $to) => ['student_id' => (int) $sid, 'from' => null, 'to' => $to],
                    array_keys($d['changed'] ?? []), $d['changed'] ?? []);
                foreach ($changes as $c) {
                    $names[$c['student_id']] = true;
                }

                return ['id' => $r->id, 'at' => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(), 'by' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')),
                    'reason' => $d['reason'] ?? null, 'changes' => $changes];
            });
        $people = DB::table('users')->whereIn('id', array_keys($names))->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name.' '.$u->last_name)]);

        return response()->json(['data' => $rows->map(function ($r) use ($people) {
            $r['changes'] = array_map(fn ($c) => $c + ['name' => $people[$c['student_id']] ?? '#'.$c['student_id']], $r['changes']);

            return $r;
        })]);
    }

    /** O'tgan, bekor qilinmagan, davomati to'liq belgilanmagan darslar (o'qituvchi — o'z guruhlari). */
    public function unmarked(Request $request, Settings $settings): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['group_id' => ['nullable', 'integer']]);
        $groupIds = GroupController::visible($user)->where('active', true)
            ->when(! $user->hasPermission('group.manage'), fn ($q) => $q->where('teacher_id', $user->id))
            ->when($data['group_id'] ?? null, fn ($q, $g) => $q->where('id', $g))
            ->pluck('id');
        $rows = DB::table('class_sessions')
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->whereIn('class_sessions.group_id', $groupIds)
            ->where('class_sessions.cancelled', false)
            ->where('class_sessions.starts_at', '<=', now())
            ->where('class_sessions.starts_at', '>=', now()->subDays($settings->int('attendance.unmarked_lookback_days')))
            ->select('class_sessions.id', 'class_sessions.starts_at', 'class_sessions.group_id', 'study_groups.name as group_name')
            ->selectSub(DB::table('group_members')->whereColumn('group_members.group_id', 'class_sessions.group_id')
                ->whereNull('group_members.left_at')->selectRaw('count(*)'), 'members')
            ->selectSub(DB::table('attendances')->whereColumn('attendances.session_id', 'class_sessions.id')->selectRaw('count(*)'), 'marked')
            ->orderByDesc('class_sessions.starts_at')
            ->get()
            ->filter(fn ($r) => (int) $r->members > 0 && (int) $r->marked < (int) $r->members)
            ->map(fn ($r) => ['id' => $r->id, 'starts_at' => \Illuminate\Support\Carbon::parse($r->starts_at)->toIso8601String(), 'group' => ['id' => $r->group_id, 'name' => $r->group_name],
                'members' => (int) $r->members, 'marked' => (int) $r->marked])
            ->values();

        return response()->json(['data' => $rows, 'total' => $rows->count()]);
    }

    /** O'qituvchi/admin uchun tahrir oynasi — UI ham shu ma'lumotni ko'rsatadi. */
    public static function editWindow(Settings $settings, ClassSession $session, bool $admin): array
    {
        if ($session->cancelled) {
            return ['can_edit' => false, 'reason_required' => false, 'message' => 'Dars bekor qilingan.'];
        }
        if ($session->starts_at->isFuture()) {
            return ['can_edit' => false, 'reason_required' => false, 'message' => 'Dars hali boshlanmagan.'];
        }
        $age = (int) floor($session->starts_at->diffInDays(now(), true));
        $reasonAfter = $settings->int('attendance.edit_reason_required_after_days');
        $reason = $reasonAfter > 0 && $age >= $reasonAfter;
        $days = $settings->int('attendance.teacher_edit_days');
        if (! $admin && $days > 0 && $age > $days) {
            return ['can_edit' => false, 'reason_required' => $reason,
                'message' => "Bu dars $age kun oldin bo'lgan. O'qituvchi davomatni $days kun ichida o'zgartira oladi — administratorga murojaat qiling."];
        }

        return ['can_edit' => true, 'reason_required' => $reason,
            'deadline' => ! $admin && $days > 0 ? $session->starts_at->copy()->addDays($days)->toIso8601String() : null];
    }

    /**
     * Dars varaqasidagi o'quvchilar: hozirgi a'zolar, dars paytida a'zo bo'lib keyin chiqib ketganlar
     * va shu darsda yozuvi borlar. Chiqib ketganlar ham ko'rinadi — o'tgan davomatini tuzatish mumkin.
     */
    public static function roster(int $groupId, ClassSession $session, array $markedIds = []): \Illuminate\Support\Collection
    {
        return DB::table('group_members')->join('users', 'users.id', '=', 'group_members.student_id')
            ->where('group_members.group_id', $groupId)
            ->where(fn ($w) => $w->whereNull('group_members.left_at')
                ->orWhere('group_members.left_at', '>', $session->starts_at)
                ->orWhereIn('group_members.student_id', $markedIds))
            ->orderBy('users.first_name')
            ->get(['users.id', 'users.first_name', 'users.last_name', 'group_members.left_at']);
    }
}
