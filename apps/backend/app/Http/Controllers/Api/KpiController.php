<?php

namespace App\Http\Controllers\Api;

use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ko'rsatkichlar (KPI v1) va uyga vazifa hisoboti (report.view). Hammasi SQL'da yig'iladi (xotiraga satrlar yuklanmaydi).
 * O'qituvchi — faqat o'z guruhlari; pul ko'rsatkichlari — faqat billing.manage.
 * Yangi/chiqqan o'quvchilar hozircha a'zolik sanalaridan (group_members.joined_at/left_at);
 * Sprint 2 dagi a'zolik holatlari (enrollments) qo'shilgach — shu yerda almashtiriladi.
 */
class KpiController
{
    public function __construct(private Settings $settings) {}

    public function kpi(Request $request): JsonResponse
    {
        $data = $request->validate([
            'months' => ['nullable', 'integer', 'between:2,24'],
            'group_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'], 'course_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $n = $data['months'] ?? $this->settings->int('reports.kpi_months');
        $groupIds = $this->groupIds($request, $data);
        $months = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $start = now()->startOfMonth()->subMonthsNoOverflow($i);
            $months[] = ['key' => $start->format('Y-m'), 'start' => $start, 'end' => $i === 0 ? now() : $start->copy()->endOfMonth()];
        }
        $rangeStart = $months[0]['start'];
        $rangeEnd = end($months)['end'];
        $m = $this->monthExpr('class_sessions.starts_at');

        // Davomat: oylar bo'yicha holatlar soni.
        $att = DB::table('attendances')->join('class_sessions', 'class_sessions.id', '=', 'attendances.session_id')
            ->whereIn('class_sessions.group_id', $groupIds)->where('class_sessions.cancelled', false)
            ->whereBetween('class_sessions.starts_at', [$rangeStart, $rangeEnd])
            ->selectRaw("$m as m, attendances.status, count(*) as c")->groupByRaw("$m, attendances.status")->get()
            ->groupBy('m')->map(fn ($rows) => $rows->pluck('c', 'status')->map(fn ($v) => (int) $v)->all());

        // Testlar: topshirilgan urinishlar va o'tganlar.
        $courseIds = DB::table('study_groups')->whereIn('id', $groupIds)->distinct()->pluck('course_id');
        $studentIds = DB::table('group_members')->whereIn('group_id', $groupIds)->distinct()->select('student_id');
        $qm = $this->monthExpr('quiz_attempts.submitted_at');
        $quiz = DB::table('quiz_attempts')->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->whereIn('quizzes.course_id', $courseIds)->whereIn('quiz_attempts.student_id', $studentIds)
            ->whereNotNull('quiz_attempts.submitted_at')->whereBetween('quiz_attempts.submitted_at', [$rangeStart, $rangeEnd])
            ->selectRaw("$qm as m, count(*) as attempts, sum(case when quiz_attempts.passed then 1 else 0 end) as passed")
            ->groupByRaw($qm)->get()->keyBy('m');

        $homework = $this->homeworkByMonth($groupIds, $rangeStart, $rangeEnd);
        $hours = $this->teacherMinutes($groupIds, $rangeStart, $rangeEnd);

        $series = [];
        $prevActive = $this->activeAt($groupIds, $rangeStart->copy()->subSecond());
        foreach ($months as $mo) {
            $k = $mo['key'];
            $counts = $att[$k] ?? [];
            $active = $this->activeAt($groupIds, $mo['end']);
            $new = $this->movedIn($groupIds, 'joined_at', $mo['start'], $mo['end']);
            $left = $this->movedIn($groupIds, 'left_at', $mo['start'], $mo['end']);
            $q = $quiz[$k] ?? null;
            $h = $homework[$k] ?? null;
            $series[] = [
                'month' => $k,
                'partial' => $mo['end']->isSameDay(now()) && ! $mo['end']->isLastOfMonth(),
                'attendance_rate' => self::attendanceRate($counts),
                'attendance_marks' => array_sum($counts),
                'active_students' => $active,
                'new_students' => $new,
                'left_students' => $left,
                'churn_percent' => $prevActive > 0 ? round($left / $prevActive * 100, 1) : null,
                'quiz_attempts' => (int) ($q->attempts ?? 0),
                'quiz_pass_rate' => $q && $q->attempts ? round($q->passed / $q->attempts * 100, 1) : null,
                'homework_expected' => (int) ($h['expected'] ?? 0),
                'homework_submitted' => (int) ($h['submitted'] ?? 0),
                'homework_rate' => ! empty($h['expected']) ? round($h['submitted'] / $h['expected'] * 100, 1) : null,
                'homework_avg_percent' => $h['avg_percent'] ?? null,
                'teacher_hours' => round(array_sum(array_column($hours[$k] ?? [], 'minutes')) / 60, 1),
                'sessions' => array_sum(array_column($hours[$k] ?? [], 'sessions')),
            ];
            $prevActive = $active;
        }

        $teacherIds = collect($hours)->flatMap(fn ($t) => array_keys($t))->unique()->values();
        $names = DB::table('users')->whereIn('id', $teacherIds)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name.' '.$u->last_name)]);
        $teachers = $teacherIds->map(fn ($id) => [
            'teacher_id' => (int) $id,
            'name' => $names[$id] ?? '#'.$id,
            'hours' => collect($months)->mapWithKeys(fn ($mo) => [$mo['key'] => round(($hours[$mo['key']][$id]['minutes'] ?? 0) / 60, 1)]),
            'sessions' => collect($months)->mapWithKeys(fn ($mo) => [$mo['key'] => (int) ($hours[$mo['key']][$id]['sessions'] ?? 0)]),
        ])->sortBy('name')->values();

        $students = DB::table('group_members')->whereIn('group_id', $groupIds)->whereNull('left_at')->distinct()->pluck('student_id');
        $linked = $students->isEmpty() ? 0 : DB::table('telegram_links')->whereIn('user_id', $students)->count();

        return response()->json([
            'months' => array_column($months, 'key'),
            'series' => $series,
            'teachers' => $teachers,
            'telegram_reach_percent' => $students->count() ? round($linked / $students->count() * 100, 1) : null,
            'targets' => ['attendance' => $this->settings->int('reports.attendance_target'), 'quiz_pass' => $this->settings->int('reports.quiz_pass_target')],
            'money' => $user->hasPermission('billing.manage') ? $this->money($months, $rangeStart, $rangeEnd) : null,
            'source' => ['students' => 'membership_dates'],
        ]);
    }

    /** Drill-down: shu oyda qo'shilgan / chiqqan / oy oxirida faol o'quvchilar ro'yxati. */
    public function students(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:new,left,active'], 'month' => ['required', 'date_format:Y-m'],
            'group_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'], 'course_id' => ['nullable', 'integer'],
        ]);
        $groupIds = $this->groupIds($request, $data);
        $start = Carbon::createFromFormat('!Y-m', $data['month'])->startOfMonth();
        $end = $start->isSameMonth(now()) ? now() : $start->copy()->endOfMonth();
        $q = DB::table('group_members')
            ->join('users', 'users.id', '=', 'group_members.student_id')
            ->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->whereIn('group_members.group_id', $groupIds);
        match ($data['type']) {
            'new' => $q->whereBetween('group_members.joined_at', [$start, $end]),
            'left' => $q->whereBetween('group_members.left_at', [$start, $end]),
            'active' => $q->where('group_members.joined_at', '<=', $end)->where(fn ($w) => $w->whereNull('group_members.left_at')->orWhere('group_members.left_at', '>', $end)),
        };
        $staffPhones = $request->user()->hasPermission('user.manage') || $request->user()->hasPermission('group.manage');
        $rows = $q->orderBy('users.first_name')->limit(2000)
            ->get(['users.id', 'users.first_name', 'users.last_name', 'users.phone', 'study_groups.id as group_id', 'study_groups.name as group_name',
                'group_members.joined_at', 'group_members.left_at'])
            ->map(fn ($r) => [
                'student_id' => (int) $r->id, 'name' => trim($r->first_name.' '.$r->last_name), 'phone' => $staffPhones ? $r->phone : null,
                'group_id' => (int) $r->group_id, 'group' => $r->group_name,
                'joined_at' => $r->joined_at ? Carbon::parse($r->joined_at)->toDateString() : null,
                'left_at' => $r->left_at ? Carbon::parse($r->left_at)->toDateString() : null,
            ]);

        return response()->json(['type' => $data['type'], 'month' => $data['month'], 'data' => $rows]);
    }

    /** Uyga vazifa: guruhlar bo'yicha topshirish foizi va o'rtacha baho (muddati davr ichida bo'lgan vazifalar). */
    public function homework(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'group_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'], 'course_id' => ['nullable', 'integer'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : $to->copy()->subDays($this->settings->int('reports.default_days') - 1)->startOfDay();
        abort_if($from->diffInDays($to) > $this->settings->int('reports.max_days'), 422, 'Davr juda katta.');
        $groupIds = $this->groupIds($request, $data);
        $scaleMax = $this->settings->int('marks.scale_max');

        $base = $this->expectedQuery($groupIds)->whereBetween('assignments.due_at', [$from, $to]);
        $rows = (clone $base)->groupBy('assignments.group_id')
            ->selectRaw('assignments.group_id, count(distinct assignments.id) as assignments, count(*) as expected,
                sum(case when s.submitted_at is not null then 1 else 0 end) as submitted,
                sum(case when s.submitted_at is not null and s.submitted_at > assignments.due_at then 1 else 0 end) as late,
                avg(case when s.score is not null then s.score * 100.0 / coalesce(assignments.max_score, ?) end) as avg_percent', [$scaleMax])
            ->get()->keyBy('group_id');
        $marks = DB::table('session_marks')->join('class_sessions', 'class_sessions.id', '=', 'session_marks.session_id')
            ->whereIn('class_sessions.group_id', $groupIds)->whereBetween('class_sessions.starts_at', [$from, $to])
            ->groupBy('class_sessions.group_id')
            ->selectRaw('class_sessions.group_id, count(*) as marks, avg(session_marks.score * 100.0 / ?) as avg_percent', [$scaleMax])
            ->get()->keyBy('group_id');
        $groups = DB::table('study_groups')->leftJoin('users', 'users.id', '=', 'study_groups.teacher_id')
            ->whereIn('study_groups.id', $rows->keys()->merge($marks->keys())->unique())
            ->get(['study_groups.id', 'study_groups.name', 'users.first_name', 'users.last_name']);

        $data = $groups->map(function ($g) use ($rows, $marks) {
            $r = $rows[$g->id] ?? null;
            $mk = $marks[$g->id] ?? null;

            return [
                'group_id' => (int) $g->id, 'group' => $g->name, 'teacher' => trim($g->first_name.' '.$g->last_name),
                'assignments' => (int) ($r->assignments ?? 0), 'expected' => (int) ($r->expected ?? 0),
                'submitted' => (int) ($r->submitted ?? 0), 'late' => (int) ($r->late ?? 0),
                'submission_rate' => $r && $r->expected ? round($r->submitted / $r->expected * 100, 1) : null,
                'avg_homework_percent' => $r && $r->avg_percent !== null ? round((float) $r->avg_percent, 1) : null,
                'session_marks' => (int) ($mk->marks ?? 0),
                'avg_mark_percent' => $mk && $mk->avg_percent !== null ? round((float) $mk->avg_percent, 1) : null,
            ];
        })->sortBy('submission_rate')->values();

        return response()->json(['period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'data' => $data, 'scale_max' => $scaleMax]);
    }

    // ── Yordamchilar ─────────────────────────────────────────

    /** @return list<int> */
    private function groupIds(Request $request, array $data): array
    {
        $user = $request->user();

        return GroupController::visible($user)
            ->when(! $user->hasPermission('group.manage'), fn ($q) => $q->where('teacher_id', $user->id))
            ->when($data['group_id'] ?? null, fn ($q, $v) => $q->where('id', $v))
            ->when($data['teacher_id'] ?? null, fn ($q, $v) => $q->where('teacher_id', $v))
            ->when($data['course_id'] ?? null, fn ($q, $v) => $q->where('course_id', $v))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function activeAt(array $groupIds, Carbon $at): int
    {
        return DB::table('group_members')->whereIn('group_id', $groupIds)
            ->where('joined_at', '<=', $at)
            ->where(fn ($w) => $w->whereNull('left_at')->orWhere('left_at', '>', $at))
            ->distinct()->count('student_id');
    }

    private function movedIn(array $groupIds, string $col, Carbon $from, Carbon $to): int
    {
        return DB::table('group_members')->whereIn('group_id', $groupIds)->whereBetween($col, [$from, $to])->distinct()->count('student_id');
    }

    /** Vazifa × o'sha paytdagi a'zo (muddat vaqtida guruhda bo'lganlar) + ularning javobi. */
    private function expectedQuery(array $groupIds)
    {
        return DB::table('assignments')
            ->join('group_members as gm', function ($j) {
                $j->on('gm.group_id', '=', 'assignments.group_id')->on('gm.joined_at', '<=', 'assignments.due_at');
            })
            ->leftJoin('assignment_submissions as s', fn ($j) => $j->on('s.assignment_id', '=', 'assignments.id')->on('s.student_id', '=', 'gm.student_id'))
            ->whereIn('assignments.group_id', $groupIds)->whereNotNull('assignments.due_at')
            ->where(fn ($w) => $w->whereNull('gm.left_at')->orWhereColumn('gm.left_at', '>', 'assignments.due_at'));
    }

    private function homeworkByMonth(array $groupIds, Carbon $from, Carbon $to): array
    {
        $m = $this->monthExpr('assignments.due_at');
        $scaleMax = $this->settings->int('marks.scale_max');

        return $this->expectedQuery($groupIds)->whereBetween('assignments.due_at', [$from, min($to, now())])
            ->selectRaw("$m as m, count(*) as expected, sum(case when s.submitted_at is not null then 1 else 0 end) as submitted,
                avg(case when s.score is not null then s.score * 100.0 / coalesce(assignments.max_score, ?) end) as avg_percent", [$scaleMax])
            ->groupByRaw($m)->get()
            ->mapWithKeys(fn ($r) => [$r->m => ['expected' => (int) $r->expected, 'submitted' => (int) $r->submitted,
                'avg_percent' => $r->avg_percent !== null ? round((float) $r->avg_percent, 1) : null]])->all();
    }

    /** @return array<string, array<int, array{minutes:int, sessions:int}>> oy → o'qituvchi → daqiqa */
    private function teacherMinutes(array $groupIds, Carbon $from, Carbon $to): array
    {
        $m = $this->monthExpr('class_sessions.starts_at');
        $minutes = DB::connection()->getDriverName() === 'sqlite'
            ? 'round((julianday(class_sessions.ends_at) - julianday(class_sessions.starts_at)) * 1440)'
            : 'timestampdiff(minute, class_sessions.starts_at, class_sessions.ends_at)';
        $out = [];
        DB::table('class_sessions')->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->whereIn('class_sessions.group_id', $groupIds)->where('class_sessions.cancelled', false)
            ->whereBetween('class_sessions.starts_at', [$from, min($to, now())])
            ->selectRaw("$m as m, study_groups.teacher_id, sum($minutes) as minutes, count(*) as sessions")
            ->groupByRaw("$m, study_groups.teacher_id")->get()
            ->each(function ($r) use (&$out) {
                $out[$r->m][(int) $r->teacher_id] = ['minutes' => (int) $r->minutes, 'sessions' => (int) $r->sessions];
            });

        return $out;
    }

    private function money(array $months, Carbon $from, Carbon $to): array
    {
        $m = $this->monthExpr('entry_date');
        $rows = DB::table('billing_entries')->whereNull('voided_at')
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("$m as m, type, sum(amount) as total")->groupByRaw("$m, type")->get()
            ->groupBy('m')->map(fn ($c) => $c->pluck('total', 'type')->map(fn ($v) => (int) $v)->all());
        $balances = DB::table('billing_entries')->whereNull('voided_at')->groupBy('student_id')->selectRaw('student_id, sum(amount) as balance');
        $debt = DB::query()->fromSub($balances, 'b')->where('balance', '<', 0)->selectRaw('count(*) as debtors, coalesce(sum(balance), 0) as debt')->first();

        return [
            'currency' => (string) $this->settings->get('billing.currency'),
            'series' => array_map(fn ($mo) => [
                'month' => $mo['key'],
                'income' => (int) ($rows[$mo['key']]['PAYMENT'] ?? 0),
                'charged' => -(int) ($rows[$mo['key']]['CHARGE'] ?? 0),
                'adjustments' => (int) ($rows[$mo['key']]['ADJUSTMENT'] ?? 0),
            ], $months),
            'debt_total' => -(int) $debt->debt,
            'debtors' => (int) $debt->debtors,
        ];
    }

    private static function attendanceRate(array $counts): ?float
    {
        $counted = array_sum($counts) - ($counts['EXCUSED'] ?? 0);

        return $counted > 0 ? round((($counts['PRESENT'] ?? 0) + ($counts['LATE'] ?? 0)) / $counted * 100, 1) : null;
    }

    /** "YYYY-MM" ifodasi (MySQL va SQLite uchun bir xil). */
    private function monthExpr(string $col): string
    {
        return "substr($col, 1, 7)";
    }
}
