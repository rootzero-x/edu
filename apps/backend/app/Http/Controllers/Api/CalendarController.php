<?php

namespace App\Http\Controllers\Api;

use App\Models\ClassSession;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Taqvim: ko'rinadigan guruhlarning mashg'ulotlari oraliq bo'yicha (hafta/oy).
 * Web /dashboard/calendar va Mini App "Jadval" shu endpoint'dan foydalanadi.
 */
class CalendarController
{
    public function index(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'teacher_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);
        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();
        $maxDays = $settings->int('calendar.max_days');
        abort_if($from->diffInDays($to) > $maxDays, 422, "Oraliq juda katta ($maxDays kungacha).");

        $user = $request->user();
        $visible = GroupController::visible($user);
        $groups = (clone $visible)
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('active', true))
            ->with(['course:id,title', 'teacher:id,first_name,last_name', 'roomRef:id,name'])
            ->get(['id', 'name', 'course_id', 'teacher_id', 'room_id', 'room', 'active'])
            ->keyBy('id');
        // Filtrlar uchun ro'yxatlar (faqat foydalanuvchiga ko'rinadigan guruhlardan).
        $filters = [
            'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->sortBy('name')->values(),
            'teachers' => $groups->pluck('teacher')->filter()->unique('id')
                ->map(fn ($t) => ['id' => $t->id, 'name' => trim($t->first_name.' '.$t->last_name)])->sortBy('name')->values(),
            'rooms' => $groups->pluck('roomRef')->filter()->unique('id')->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->sortBy('name')->values(),
        ];

        $groupIds = $groups
            ->when($data['group_id'] ?? null, fn ($c, $id) => $c->where('id', (int) $id))
            ->when($data['teacher_id'] ?? null, fn ($c, $id) => $c->where('teacher_id', (int) $id))
            ->when($data['room_id'] ?? null, fn ($c, $id) => $c->where('room_id', (int) $id))
            ->keys()->all();

        $members = DB::table('group_members')->whereIn('group_id', $groupIds)->whereNull('left_at')
            ->selectRaw('group_id, count(*) as c')->groupBy('group_id')->pluck('c', 'group_id');
        $homework = DB::table('assignments')->whereIn('group_id', $groupIds)->whereNotNull('session_id')
            ->selectRaw('session_id, count(*) as c')->groupBy('session_id')->pluck('c', 'session_id');
        $marker = $user->hasPermission('attendance.mark');
        $manager = $user->hasPermission('group.manage');

        $sessions = ClassSession::query()
            ->whereIn('group_id', $groupIds)
            ->whereBetween('starts_at', [$from, $to])
            ->withCount('attendances as marked_count')
            ->orderBy('starts_at')
            ->limit($settings->int('calendar.max_items'))
            ->get()
            ->map(function (ClassSession $s) use ($groups, $members, $homework, $marker, $manager, $user) {
                $g = $groups[$s->group_id];

                return [
                    'id' => $s->id,
                    'group_id' => $g->id,
                    'group_name' => $g->name,
                    'course_title' => $g->course?->title,
                    'teacher_id' => $g->teacher_id,
                    'teacher' => $g->teacher ? trim($g->teacher->first_name.' '.$g->teacher->last_name) : null,
                    'room_id' => $g->room_id,
                    'room' => $g->roomRef?->name ?? $g->room,
                    'topic' => $s->topic,
                    'starts_at' => $s->starts_at->toIso8601String(),
                    'ends_at' => $s->ends_at->toIso8601String(),
                    'cancelled' => $s->cancelled,
                    'marked_count' => $marker ? (int) $s->marked_count : null,
                    'students_count' => (int) ($members[$s->group_id] ?? 0),
                    'homework_count' => (int) ($homework[$s->id] ?? 0),
                    'can_mark' => $marker && ! $s->cancelled && ! $s->starts_at->isFuture()
                        && ($manager || (int) $g->teacher_id === (int) $user->id),
                ];
            })->values();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data' => $sessions,
            'filters' => $filters,
        ]);
    }
}
