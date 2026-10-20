<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance;
use App\Support\Csv;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Hisobotlar (report.view). O'qituvchi faqat o'z guruhlarini, boshqaruvchi — hammasini ko'radi. */
class ReportController
{
    private const STATUSES = [Attendance::PRESENT, Attendance::LATE, Attendance::ABSENT, Attendance::EXCUSED];

    public function attendance(Request $request, Settings $settings): JsonResponse
    {
        [$from, $to, $groupIds] = $this->scope($request, $settings);
        $rows = $this->attendanceRows($groupIds, $from, $to);

        $byDay = $rows->groupBy(fn ($r) => substr((string) $r->starts_at, 0, 10))->sortKeys()
            ->map(fn (Collection $c, $day) => ['date' => $day] + $this->summarize($c->pluck('status')->all()))->values();

        $byGroup = $rows->groupBy('group_id')->map(fn (Collection $c) => [
            'group_id' => (int) $c->first()->group_id, 'group' => $c->first()->group_name,
            'teacher' => trim($c->first()->t_first.' '.$c->first()->t_last), 'course' => $c->first()->course_title,
            'sessions' => $c->pluck('session_id')->unique()->count(),
        ] + $this->summarize($c->pluck('status')->all()))->sortBy('rate')->values();

        $threshold = $settings->int('reports.risk_threshold_percent');
        $students = $rows->groupBy('student_id')->map(fn (Collection $c) => [
            'student_id' => (int) $c->first()->student_id, 'name' => trim($c->first()->s_first.' '.$c->first()->s_last),
            'phone' => $c->first()->s_phone, 'groups' => $c->pluck('group_name')->unique()->values(),
        ] + $this->summarize($c->pluck('status')->all()));
        $risk = $students->filter(fn ($s) => $s['rate'] !== null && $s['rate'] < $threshold)
            ->sortBy([['rate', 'asc'], ['counts.ABSENT', 'desc']])->take($settings->int('reports.top_n'))->values();

        // Belgilanmagan = belgilanganlar faol a'zolardan kam (AttendanceController::unmarked qoidasi).
        $unmarked = DB::table('class_sessions')->whereIn('group_id', $groupIds)->where('cancelled', false)
            ->whereBetween('starts_at', [$from, min($to, now())])
            ->whereRaw('(select count(*) from attendances where attendances.session_id = class_sessions.id)
                < (select count(*) from group_members where group_members.group_id = class_sessions.group_id and group_members.left_at is null)')
            ->count();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'total' => $this->summarize($rows->pluck('status')->all()) + [
                'sessions' => $rows->pluck('session_id')->unique()->count(),
                'students' => $students->count(),
                'unmarked_sessions' => $unmarked,
            ],
            'by_day' => $byDay,
            'by_group' => $byGroup,
            'risk' => $risk,
            'risk_threshold' => $threshold,
        ]);
    }

    public function quizzes(Request $request, Settings $settings): JsonResponse
    {
        [$from, $to, $groupIds] = $this->scope($request, $settings);
        $courseIds = DB::table('study_groups')->whereIn('id', $groupIds)->distinct()->pluck('course_id');
        // Guruhdan chiqqan o'quvchilar standart bo'yicha hisobga olinmaydi (include_left=1 — ular ham).
        $includeLeft = $request->boolean('include_left');
        $studentIds = DB::table('group_members')->whereIn('group_id', $groupIds)
            ->when(! $includeLeft, fn ($q) => $q->whereNull('left_at'))->distinct()->pluck('student_id');

        $data = DB::table('quiz_attempts')
            ->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('courses', 'courses.id', '=', 'quizzes.course_id')
            ->whereIn('quizzes.course_id', $courseIds)->whereIn('quiz_attempts.student_id', $studentIds)
            ->whereNotNull('quiz_attempts.submitted_at')
            ->whereBetween('quiz_attempts.submitted_at', [$from, $to])
            ->groupBy('quizzes.id', 'quizzes.title', 'quizzes.is_final', 'courses.title')
            ->selectRaw('quizzes.id, quizzes.title, quizzes.is_final, courses.title as course, count(*) as attempts,
                count(distinct quiz_attempts.student_id) as students, avg(quiz_attempts.percent) as avg_percent,
                sum(case when quiz_attempts.passed then 1 else 0 end) as passed')
            ->orderBy('courses.title')->orderBy('quizzes.title')
            ->get()
            ->map(fn ($r) => [
                'quiz_id' => (int) $r->id, 'title' => $r->title, 'course' => $r->course, 'is_final' => (bool) $r->is_final,
                'attempts' => (int) $r->attempts, 'students' => (int) $r->students,
                'avg_percent' => round((float) $r->avg_percent, 1),
                'pass_rate' => $r->attempts ? round($r->passed / $r->attempts * 100, 1) : null,
            ]);

        return response()->json(['period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'data' => $data]);
    }

    public function overview(Request $request, Settings $settings): JsonResponse
    {
        [$from, $to, $groupIds] = $this->scope($request, $settings);
        $students = DB::table('group_members')->whereIn('group_id', $groupIds)->whereNull('left_at')->distinct()->count('student_id');

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'groups' => count($groupIds),
            'students' => $students,
            'courses' => DB::table('study_groups')->whereIn('id', $groupIds)->distinct()->count('course_id'),
            'sessions' => DB::table('class_sessions')->whereIn('group_id', $groupIds)->where('cancelled', false)->whereBetween('starts_at', [$from, $to])->count(),
            'new_students' => DB::table('group_members')->whereIn('group_id', $groupIds)->whereBetween('joined_at', [$from, $to])->distinct()->count('student_id'),
            'certificates' => DB::table('certificates')->whereIn('group_id', $groupIds)->whereNull('revoked_at')->whereBetween('issued_at', [$from, $to])->count(),
        ]);
    }

    /** Excel uchun CSV (UTF-8 BOM, ; ajratuvchi): o'quvchi × guruh kesimida davomat. */
    public function attendanceCsv(Request $request, Settings $settings): StreamedResponse
    {
        [$from, $to, $groupIds] = $this->scope($request, $settings);
        $rows = $this->attendanceRows($groupIds, $from, $to);
        $labels = (array) $settings->get('attendance.status_labels');
        $name = 'davomat_'.$from->format('Ymd').'_'.$to->format('Ymd').'.csv';
        $locale = (string) $settings->get('site.default_locale');
        \App\Support\Audit::log('report.attendance_csv', 'report', null, ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'groups' => count($groupIds)]);

        return response()->streamDownload(function () use ($rows, $labels, $locale) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge(['Guruh', "O'quvchi", 'Telefon'], array_map(fn ($s) => $labels[$s] ?? $s, self::STATUSES), ['Davomat %']), ';');
            foreach ($rows->groupBy(fn ($r) => $r->group_id.':'.$r->student_id) as $c) {
                $sum = $this->summarize($c->pluck('status')->all());
                fputcsv($out, array_merge(
                    array_map(Csv::cell(...), [$c->first()->group_name, trim($c->first()->s_first.' '.$c->first()->s_last), (string) $c->first()->s_phone]),
                    array_map(fn ($s) => $sum['counts'][$s], self::STATUSES),
                    [Csv::decimal($sum['rate'], $locale)],
                ), ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0:Carbon,1:Carbon,2:list<int>} */
    private function scope(Request $request, Settings $settings): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'],
            'course_id' => ['nullable', 'integer'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays($settings->int('reports.default_days') - 1)->startOfDay();
        abort_if($from->diffInDays($to) > $settings->int('reports.max_days'), 422, 'Davr juda katta.');

        $user = $request->user();
        $groupIds = GroupController::visible($user)
            ->when(! $user->hasPermission('group.manage'), fn ($q) => $q->where('teacher_id', $user->id))
            ->when($data['group_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($data['teacher_id'] ?? null, fn ($q, $v) => $q->where('teacher_id', $v))
            ->when($data['course_id'] ?? null, fn ($q, $v) => $q->where('course_id', $v))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [$from, $to, $groupIds];
    }

    private function attendanceRows(array $groupIds, Carbon $from, Carbon $to): Collection
    {
        return DB::table('attendances')
            ->join('class_sessions', 'class_sessions.id', '=', 'attendances.session_id')
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->join('courses', 'courses.id', '=', 'study_groups.course_id')
            ->join('users as s', 's.id', '=', 'attendances.student_id')
            ->join('users as t', 't.id', '=', 'study_groups.teacher_id')
            ->whereIn('class_sessions.group_id', $groupIds)
            ->where('class_sessions.cancelled', false)
            ->whereBetween('class_sessions.starts_at', [$from, $to])
            ->get(['attendances.status', 'attendances.student_id', 'class_sessions.id as session_id', 'class_sessions.starts_at',
                'study_groups.id as group_id', 'study_groups.name as group_name', 'courses.title as course_title',
                's.first_name as s_first', 's.last_name as s_last', 's.phone as s_phone', 't.first_name as t_first', 't.last_name as t_last']);
    }

    /** @return array{counts: array<string,int>, rate: float|null} */
    private function summarize(array $statuses): array
    {
        return [
            'counts' => array_merge(array_fill_keys(self::STATUSES, 0), array_count_values($statuses)),
            'rate' => $statuses ? Attendance::rate($statuses) : null,
        ];
    }
}
