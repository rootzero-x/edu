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

        return response()->json(['session' => $session, 'group' => ['id' => $group->id, 'name' => $group->name], 'rows' => $rows]);
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
        $days = $settings->int('attendance.teacher_edit_days');
        abort_if(! $admin && $session->starts_at->lt(now()->subDays($days)), 403, "Davomatni faqat $days kun ichida o'zgartirish mumkin.");

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.student_id' => ['required', 'integer'],
            'items.*.status' => ['nullable', Rule::in([Attendance::PRESENT, Attendance::LATE, Attendance::ABSENT, Attendance::EXCUSED])],
            'items.*.arrived_at' => ['nullable', 'date_format:H:i'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        $memberIds = $group->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $before = DB::table('attendances')->where('session_id', $session->id)->pluck('status', 'student_id');
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
        Audit::log('attendance.mark', 'session', $session->id, ['changed' => $changed]);

        $notified = 0;
        if ($settings->bool('attendance.notify_parents')) {
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

    private function isSelfOrChild(int $userId, int $studentId): bool
    {
        return $userId === $studentId
            || DB::table('parent_child')->where(['parent_id' => $userId, 'child_id' => $studentId])->exists();
    }
}
