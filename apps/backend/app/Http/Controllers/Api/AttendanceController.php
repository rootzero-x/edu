<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance;
use App\Models\ClassSession;
use App\Support\Audit;
use App\Support\Settings;
use App\Telegram\Notifier;
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
        $students = $group->students()->select('users.id', 'first_name', 'last_name')->orderBy('first_name')->get();
        $canSeeAll = $request->user()->hasPermission('attendance.mark');

        $rows = $students->filter(fn ($s) => $canSeeAll || $this->isSelfOrChild($request->user()->id, $s->id))
            ->map(fn ($s) => [
                'student_id' => $s->id,
                'name' => trim($s->first_name.' '.$s->last_name),
                'status' => $records[$s->id]->status ?? null,
                'arrived_at' => $records[$s->id]->arrived_at ?? null,
                'note' => $records[$s->id]->note ?? null,
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
    public function mark(Request $request, Settings $settings, Notifier $notifier, ClassSession $session): JsonResponse
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
        $memberIds = $group->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
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
        $sameDay = $session->starts_at->isSameDay(now());

        $notified = 0;
        if ($settings->bool('attendance.notify_parents') && ($sameDay || ! $settings->bool('attendance.notify_only_same_day'))) {
            $notifyOn = (array) $settings->get('attendance.notify_statuses');
            $labels = (array) $settings->get('attendance.status_labels');
            foreach ($changed as $sid => $status) {
                if (! in_array($status, $notifyOn, true)) {
                    continue;
                }
                $child = DB::table('users')->where('id', $sid)->first(['first_name', 'last_name']);
                foreach (DB::table('parent_child')->where('child_id', $sid)->pluck('parent_id') as $parentId) {
                    $notifier->sendTemplate((int) $parentId, 'attendance.'.$status, 'telegram.text.attendance_parent', [
                        'child' => trim($child->first_name.' '.$child->last_name),
                        'group' => $group->name,
                        'date' => $session->starts_at->format('d.m.Y'),
                        'time' => $session->starts_at->format('H:i'),
                        'status' => $labels[$status] ?? $status,
                    ]);
                    $notified++;
                }
            }
        }

        return response()->json(['ok' => true, 'changed' => count($changed), 'notified' => $notified]);
    }

    /** Guruh bo'yicha davomat foizi (o'tgan, bekor qilinmagan darslar). */
    public function report(Request $request, int $group): JsonResponse
    {
        $g = GroupController::findVisible($request->user(), $group);
        $rows = DB::table('attendances')
            ->join('class_sessions', 'class_sessions.id', '=', 'attendances.session_id')
            ->where('class_sessions.group_id', $g->id)->where('class_sessions.cancelled', false)
            ->get(['attendances.student_id', 'attendances.status'])
            ->groupBy('student_id');
        $students = $g->students()->select('users.id', 'first_name', 'last_name')->orderBy('first_name')->get();
        $canSeeAll = $request->user()->hasPermission('attendance.mark');

        $data = $students->filter(fn ($s) => $canSeeAll || $this->isSelfOrChild($request->user()->id, $s->id))->map(function ($s) use ($rows) {
            $statuses = ($rows[$s->id] ?? collect())->pluck('status')->all();

            return [
                'student_id' => $s->id,
                'name' => trim($s->first_name.' '.$s->last_name),
                'counts' => array_count_values($statuses) + ['PRESENT' => 0, 'LATE' => 0, 'ABSENT' => 0, 'EXCUSED' => 0],
                'rate' => Attendance::rate($statuses),
            ];
        })->values();

        return response()->json(['data' => $data]);
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

    private function isSelfOrChild(int $userId, int $studentId): bool
    {
        return $userId === $studentId
            || DB::table('parent_child')->where(['parent_id' => $userId, 'child_id' => $studentId])->exists();
    }
}
