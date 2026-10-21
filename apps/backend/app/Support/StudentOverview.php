<?php

namespace App\Support;

use App\Domain\Attendance;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitta o'quvchi haqida yig'ma ma'lumot: guruhlar (holati bilan), keyingi dars, davomat, test natijalari, balans, sertifikatlar.
 * Ota-onaning "Farzandlarim" sahifasi (web + Mini App) va xodimlarning o'quvchi profili shu yerdan oladi.
 */
final class StudentOverview
{
    public function __construct(private Settings $settings, private Enrollments $enrollments, private Billing $billing) {}

    /**
     * @param  list<int>|null  $onlyGroupIds  ko'ruvchi (o'qituvchi) faqat shu guruhlar bo'yicha ma'lumot ko'radi; null — cheklovsiz
     */
    public function build(User $student, bool $withBalance = true, int $recent = 10, ?array $onlyGroupIds = null): array
    {
        $courseIds = $onlyGroupIds === null ? null
            : DB::table('study_groups')->whereIn('id', $onlyGroupIds)->pluck('course_id')->filter()->unique()->values()->all();
        $days = $this->settings->int('ui.summary_attendance_days');
        $groups = DB::table('group_members')
            ->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->leftJoin('courses', 'courses.id', '=', 'study_groups.course_id')
            ->leftJoin('users as t', 't.id', '=', 'study_groups.teacher_id')
            ->where('group_members.student_id', $student->id)->whereNull('group_members.left_at')
            ->when($onlyGroupIds !== null, fn ($q) => $q->whereIn('study_groups.id', $onlyGroupIds))
            ->orderBy('study_groups.name')
            ->get(['study_groups.id', 'study_groups.name', 'study_groups.room', 'study_groups.active', 'courses.id as course_id', 'courses.title as course_title',
                't.first_name as t_first', 't.last_name as t_last', 'group_members.joined_at']);
        $groupIds = $groups->pluck('id')->all();
        $schedules = DB::table('group_schedules')->whereIn('group_id', $groupIds)->orderBy('weekday')->orderBy('start_time')->get()->groupBy('group_id');
        $next = DB::table('class_sessions')->whereIn('group_id', $groupIds)->where('cancelled', false)->where('starts_at', '>=', now())
            ->selectRaw('group_id, min(starts_at) as starts_at')->groupBy('group_id')->pluck('starts_at', 'group_id');

        $groupsOut = $groups->map(function ($g) use ($schedules, $next, $student) {
            $st = $this->enrollments->currentStatuses((int) $g->id, [$student->id])[$student->id] ?? ['status' => Enrollments::ACTIVE];

            return [
                'id' => (int) $g->id, 'name' => $g->name, 'room' => $g->room, 'course_id' => $g->course_id ? (int) $g->course_id : null, 'course_title' => $g->course_title,
                'teacher' => trim($g->t_first.' '.$g->t_last) ?: null, 'joined_at' => substr((string) $g->joined_at, 0, 10),
                'status' => $st['status'], 'frozen_until' => $st['until'] ?? null, 'frozen_from' => $st['frozen_from'] ?? null,
                'schedule' => ($schedules[$g->id] ?? collect())->map(fn ($s) => ['weekday' => (int) $s->weekday, 'start' => substr($s->start_time, 0, 5), 'end' => substr($s->end_time, 0, 5)])->values(),
                'next_session' => isset($next[$g->id]) ? Carbon::parse($next[$g->id])->toIso8601String() : null,
            ];
        })->values();

        $records = DB::table('attendances')
            ->join('class_sessions', 'class_sessions.id', '=', 'attendances.session_id')
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->where('attendances.student_id', $student->id)->where('class_sessions.cancelled', false)
            ->when($onlyGroupIds !== null, fn ($q) => $q->whereIn('class_sessions.group_id', $onlyGroupIds))
            ->where('class_sessions.starts_at', '>=', now()->subDays($days))
            ->orderByDesc('class_sessions.starts_at')
            ->get(['attendances.status', 'attendances.note', 'class_sessions.starts_at', 'class_sessions.topic', 'study_groups.name as group_name']);
        $statuses = $records->pluck('status')->all();

        $attempts = DB::table('quiz_attempts')->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->leftJoin('courses', 'courses.id', '=', 'quizzes.course_id')
            ->where('quiz_attempts.student_id', $student->id)->whereNotNull('quiz_attempts.submitted_at')
            ->when($courseIds !== null, fn ($q) => $q->whereIn('quizzes.course_id', $courseIds))
            ->orderByDesc('quiz_attempts.submitted_at')->limit($recent)
            ->get(['quizzes.id as quiz_id', 'quizzes.title', 'quizzes.is_final', 'courses.title as course_title', 'quiz_attempts.percent',
                'quiz_attempts.score', 'quiz_attempts.max_score', 'quiz_attempts.passed', 'quiz_attempts.submitted_at'])
            ->map(fn ($a) => [
                'quiz_id' => (int) $a->quiz_id, 'title' => $a->title, 'is_final' => (bool) $a->is_final, 'course_title' => $a->course_title,
                'percent' => $a->percent !== null ? (float) $a->percent : null, 'score' => $a->score !== null ? (int) $a->score : null,
                'max_score' => $a->max_score !== null ? (int) $a->max_score : null, 'passed' => (bool) $a->passed,
                'submitted_at' => Carbon::parse($a->submitted_at)->toIso8601String(),
            ]);

        $certs = DB::table('certificates')->join('courses', 'courses.id', '=', 'certificates.course_id')
            ->where('certificates.student_id', $student->id)
            ->when($courseIds !== null, fn ($q) => $q->whereIn('certificates.course_id', $courseIds))
            ->when(Schema::hasColumn('certificates', 'revoked_at'), fn ($q) => $q->whereNull('certificates.revoked_at'))
            ->orderByDesc('certificates.issued_at')->get(['certificates.code', 'certificates.issued_at', 'courses.title as course_title'])
            ->map(fn ($c) => ['code' => $c->code, 'course_title' => $c->course_title, 'issued_at' => substr((string) $c->issued_at, 0, 10)]);

        $balance = null;
        if ($withBalance && $this->settings->bool('billing.enabled')) {
            $b = $this->billing->balance($student->id);
            $balance = ['balance' => $b, 'debt' => max(0, -$b), 'currency' => $this->settings->get('billing.currency')];
        }

        return [
            'id' => $student->id,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'groups' => $groupsOut,
            'attendance' => [
                'days' => $days,
                'rate' => $statuses ? Attendance::rate($statuses) : null,
                'counts' => array_count_values($statuses) + array_fill_keys([Attendance::PRESENT, Attendance::LATE, Attendance::ABSENT, Attendance::EXCUSED], 0),
                'recent' => $records->take($recent)->map(fn ($r) => [
                    'starts_at' => Carbon::parse($r->starts_at)->toIso8601String(), 'group_name' => $r->group_name,
                    'topic' => $r->topic, 'status' => $r->status, 'note' => $r->note,
                ])->values(),
            ],
            'quizzes' => $attempts,
            'certificates' => $certs,
            'balance' => $balance,
        ];
    }
}
