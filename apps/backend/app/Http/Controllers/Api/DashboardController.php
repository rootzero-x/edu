<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance;
use App\Domain\Role;
use App\Models\ClassSession;
use App\Models\Course;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Bosh panel uchun yengil xulosa. Web har ui.refresh_seconds da so'raydi (polling),
 * shuning uchun faqat bir nechta agregat so'rov — og'ir ro'yxatlar yo'q.
 */
class DashboardController
{
    public function summary(Request $request, Settings $settings): JsonResponse
    {
        $user = $request->user();
        $groupIds = GroupController::visible($user)->where('active', true)->pluck('id')->all();
        $marker = $user->hasPermission('attendance.mark');
        $manager = $user->hasPermission('group.manage');

        $members = DB::table('group_members')->whereIn('group_id', $groupIds)->whereNull('left_at')
            ->selectRaw('group_id, count(*) as c')->groupBy('group_id')->pluck('c', 'group_id');

        $today = ClassSession::query()
            ->whereIn('group_id', $groupIds)
            ->whereBetween('starts_at', [now()->startOfDay(), now()->endOfDay()])
            ->with(['group:id,name,room,course_id,teacher_id', 'group.course:id,title', 'group.teacher:id,first_name,last_name'])
            ->withCount('attendances as marked_count')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassSession $s) => [
                'id' => $s->id,
                'group_id' => $s->group_id,
                'group_name' => $s->group->name,
                'course_title' => $s->group->course?->title,
                'teacher' => $s->group->teacher ? trim($s->group->teacher->first_name.' '.$s->group->teacher->last_name) : null,
                'room' => $s->group->room,
                'topic' => $s->topic,
                'starts_at' => $s->starts_at->toIso8601String(),
                'ends_at' => $s->ends_at->toIso8601String(),
                'cancelled' => $s->cancelled,
                'marked_count' => (int) $s->marked_count,
                'students_count' => (int) ($members[$s->group_id] ?? 0),
                'can_mark' => $marker && ! $s->cancelled && ! $s->starts_at->isFuture()
                    && ($manager || (int) $s->group->teacher_id === (int) $user->id),
            ])->values();

        // Davomat: belgilovchi — ko'rinadigan guruhlar bo'yicha, o'quvchi/ota-ona — faqat o'zi/farzandi.
        $days = $settings->int('ui.summary_attendance_days');
        $statuses = DB::table('attendances')
            ->join('class_sessions', 'class_sessions.id', '=', 'attendances.session_id')
            ->whereIn('class_sessions.group_id', $groupIds)
            ->where('class_sessions.cancelled', false)
            ->where('class_sessions.starts_at', '>=', now()->subDays($days))
            ->when(! $marker, fn ($q) => $q->whereIn('attendances.student_id', $this->selfAndChildren($user->id)))
            ->pluck('attendances.status')->all();

        $unmarked = null;
        if ($marker) {
            $unmarked = ClassSession::query()
                ->whereIn('group_id', $groupIds)
                ->where('cancelled', false)
                ->where('starts_at', '<=', now())
                ->where('starts_at', '>=', now()->subDays(max(1, $settings->int('attendance.teacher_edit_days'))))
                ->whereIn('group_id', $members->keys())
                ->doesntHave('attendances')
                ->count();
        }

        return response()->json([
            'role' => $user->role,
            'generated_at' => now()->toIso8601String(),
            'today' => $today,
            'stats' => [
                'today_sessions' => $today->where('cancelled', false)->count(),
                'today_marked' => $today->where('cancelled', false)->where('marked_count', '>', 0)->count(),
                'attendance_rate' => $statuses ? Attendance::rate($statuses) : null,
                'attendance_days' => $days,
                'attendance_counts' => array_count_values($statuses) + array_fill_keys([Attendance::PRESENT, Attendance::LATE, Attendance::ABSENT, Attendance::EXCUSED], 0),
                'courses' => $user->hasPermission('course.view')
                    ? Course::query()->when(! $user->hasPermission('course.manage'), fn ($q) => $q->where('published', true))->count()
                    : null,
                'groups' => count($groupIds),
                'students' => $marker
                    ? ($manager
                        ? DB::table('users')->where('role', Role::Student->value)->where('active', true)->count()
                        : DB::table('group_members')->whereIn('group_id', $groupIds)->whereNull('left_at')->distinct()->count('student_id'))
                    : null,
                'unmarked_sessions' => $unmarked,
            ],
        ]);
    }

    /** @return list<int> */
    private function selfAndChildren(int $userId): array
    {
        return array_merge([$userId], DB::table('parent_child')->where('parent_id', $userId)->pluck('child_id')->map(fn ($id) => (int) $id)->all());
    }
}
