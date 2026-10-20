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

        // Belgilanmagan darslar — /v1/attendance/unmarked bilan bir xil qoida (qisman belgilanganlar ham).
        $unmarked = $marker
            ? (int) app(AttendanceController::class)->unmarked($request, $settings)->getData(true)['total']
            : null;

        return response()->json([
            'role' => $user->role,
            'generated_at' => now()->toIso8601String(),
            'today' => $today,
            'stats' => [
                'today_sessions' => $today->where('cancelled', false)->count(),
                // To'liq belgilangan darslar (qisman belgilangan — belgilanmagan hisoblanadi, /attendance/unmarked kabi).
                'today_marked' => $today->where('cancelled', false)->filter(fn ($s) => $s['marked_count'] >= $s['students_count'])->count(),
                'attendance_rate' => $statuses ? Attendance::rate($statuses) : null,
                'attendance_days' => $days,
                'attendance_counts' => array_count_values($statuses) + array_fill_keys([Attendance::PRESENT, Attendance::LATE, Attendance::ABSENT, Attendance::EXCUSED], 0),
                'courses' => $user->hasPermission('course.view')
                    ? Course::query()->when(! $user->hasPermission('course.manage'), fn ($q) => $q->where(fn ($w) => $w->where('published', true)
                        ->when($user->hasPermission('course.author'), fn ($a) => $a->orWhere('created_by', $user->id))))->count()
                    : null,
                'groups' => count($groupIds),
                'students' => $marker
                    ? ($manager
                        ? DB::table('users')->where('role', Role::Student->value)->where('active', true)->count()
                        : DB::table('group_members')->whereIn('group_id', $groupIds)->whereNull('left_at')->distinct()->count('student_id'))
                    : null,
                'unmarked_sessions' => $unmarked,
            ],
            'setup' => $this->setup($user, $settings),
        ]);
    }

    /**
     * Yangi admin uchun "Boshlash" ro'yxati: har bir band haqiqiy ma'lumotdan belgilanadi.
     * Faqat foydalanuvchilarni boshqaruvchilarga; sozlama bandlari — sozlamalar huquqi borlarga.
     *
     * @return array{steps: list<array{key:string, done:bool, href:string}>, done:int, total:int}|null
     */
    private function setup(\App\Models\User $user, Settings $settings): ?array
    {
        if (! $settings->bool('ui.setup_checklist') || ! $user->hasPermission('user.manage')) {
            return null;
        }
        $stored = DB::table('settings')->whereIn('key', ['billing.monthly_fee'])->pluck('key')->flip();
        $canSettings = $user->hasPermission('settings.manage');
        $steps = array_values(array_filter([
            $canSettings ? ['key' => 'site', 'done' => trim((string) $settings->get('site.contact_phone')) !== '' && trim((string) $settings->get('site.address')) !== '', 'href' => '/dashboard/settings?group=site'] : null,
            $canSettings ? ['key' => 'bot', 'done' => trim((string) $settings->get('telegram.bot_username')) !== '' && (array) $settings->get('telegram.admin_chat_ids') !== [], 'href' => '/dashboard/settings?group=telegram'] : null,
            ['key' => 'telegram', 'done' => DB::table('telegram_links')->where('user_id', $user->id)->exists(), 'href' => '/dashboard/profile'],
            $canSettings && $user->hasPermission('billing.manage') ? ['key' => 'billing', 'done' => isset($stored['billing.monthly_fee']) || DB::table('billing_fee_history')->exists(), 'href' => '/dashboard/settings?group=billing'] : null,
            ['key' => 'course', 'done' => Course::query()->exists(), 'href' => '/dashboard/courses'],
            ['key' => 'teacher', 'done' => DB::table('users')->where('role', Role::Teacher->value)->exists(), 'href' => '/dashboard/users?role=TEACHER'],
            ['key' => 'group', 'done' => DB::table('study_groups')->exists(), 'href' => '/dashboard/groups'],
            ['key' => 'schedule', 'done' => DB::table('group_schedules')->exists(), 'href' => '/dashboard/groups'],
            ['key' => 'students', 'done' => DB::table('users')->where('role', Role::Student->value)->exists(), 'href' => '/dashboard/users?import=1'],
        ]));
        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return ['steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }

    /** @return list<int> */
    private function selfAndChildren(int $userId): array
    {
        return array_merge([$userId], DB::table('parent_child')->where('parent_id', $userId)->pluck('child_id')->map(fn ($id) => (int) $id)->all());
    }
}
