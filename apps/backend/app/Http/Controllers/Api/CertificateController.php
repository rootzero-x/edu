<?php

namespace App\Http\Controllers\Api;

use App\Domain\Attendance;
use App\Domain\CertificatePolicy;
use App\Models\StudyGroup;
use App\Support\Audit;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertificateController
{
    /** Guruh o'quvchilari uchun sertifikat shartlarini ko'rish (berishdan oldin). */
    public function preview(Request $request, Settings $settings, StudyGroup $group): JsonResponse
    {
        $data = $request->validate(['student_ids' => ['nullable', 'array'], 'student_ids.*' => ['integer']]);

        return response()->json(['data' => $this->evaluate($group, $settings, $data['student_ids'] ?? null)]);
    }

    /** Shartlarga mos o'quvchilarga sertifikat berish. Allaqachon berilganlar o'tkazib yuboriladi. */
    public function issue(Request $request, Settings $settings, Notifier $notifier, StudyGroup $group): JsonResponse
    {
        $data = $request->validate(['student_ids' => ['nullable', 'array'], 'student_ids.*' => ['integer']]);
        $rows = $this->evaluate($group, $settings, $data['student_ids'] ?? null);
        $issued = [];
        foreach ($rows as $r) {
            if ($r['certificate'] || $r['blockers']) {
                continue;
            }
            $code = $this->newCode($settings);
            $inserted = DB::table('certificates')->insertOrIgnore([
                'code' => $code, 'student_id' => $r['student_id'], 'course_id' => $group->course_id,
                'group_id' => $group->id, 'issued_at' => now(),
            ]);
            // Parallel berilgan bo'lsa (unique student+group) — saqlanmagan kodni yubormaymiz.
            if ($inserted) {
                $issued[] = ['student_id' => $r['student_id'], 'code' => $code, 'name' => $r['name']];
            }
        }
        // Xabarlar javob yuborilgandan keyin: katta guruhda so'rov vaqt limitiga tushmasin.
        $course = $group->course->title;
        $base = rtrim((string) $settings->get('certificate.public_base_url'), '/');
        $toNotify = $issued;
        defer(function () use ($notifier, $toNotify, $course, $base) {
            foreach ($toNotify as $i) {
                $notifier->sendTemplate($i['student_id'], 'certificate.issued', 'telegram.text.certificate_issued', [
                    'name' => $i['name'], 'course' => $course, 'url' => $base.'/'.$i['code'],
                ]);
            }
        });
        $issued = array_map(fn ($i) => ['student_id' => $i['student_id'], 'code' => $i['code']], $issued);
        Audit::log('certificate.issue', 'group', $group->id, ['issued' => $issued]);

        return response()->json(['issued' => $issued, 'data' => $this->evaluate($group, $settings, $data['student_ids'] ?? null)]);
    }

    /** O'z sertifikatlari (o'quvchi), farzandlariniki (ota-ona), o'zi o'qitgan guruhlarniki (o'qituvchi), yoki hammasi (group.manage). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = DB::table('certificates')
            ->join('users', 'users.id', '=', 'certificates.student_id')
            ->join('courses', 'courses.id', '=', 'certificates.course_id')
            ->join('study_groups', 'study_groups.id', '=', 'certificates.group_id')
            ->select('certificates.code', 'certificates.issued_at', 'certificates.student_id', 'users.first_name', 'users.last_name',
                'courses.title as course', 'study_groups.name as group')
            ->orderByDesc('certificates.issued_at');
        if (! $user->hasPermission('group.manage')) {
            $q->where(fn ($w) => $w->where('certificates.student_id', $user->id)
                ->orWhereIn('certificates.student_id', DB::table('parent_child')->where('parent_id', $user->id)->select('child_id'))
                ->orWhereIn('certificates.group_id', DB::table('study_groups')->where('teacher_id', $user->id)->select('id')));
        }
        if ($request->filled('group_id')) {
            $q->where('certificates.group_id', (int) $request->input('group_id'));
        }

        return response()->json(['data' => $q->limit(500)->get()]);
    }

    /** Ochiq tekshiruv: QR-kod shu manzilga olib keladi. Shaxsiy ma'lumot minimal. */
    public function verify(string $code): JsonResponse
    {
        $c = DB::table('certificates')
            ->join('users', 'users.id', '=', 'certificates.student_id')
            ->join('courses', 'courses.id', '=', 'certificates.course_id')
            ->where('certificates.code', $code)
            ->first(['certificates.code', 'certificates.issued_at', 'users.first_name', 'users.last_name', 'courses.title as course', 'courses.duration_months']);
        abort_unless($c, 404, 'Sertifikat topilmadi.');

        return response()->json(['certificate' => [
            'code' => $c->code, 'issued_at' => $c->issued_at, 'name' => trim($c->first_name.' '.$c->last_name),
            'course' => $c->course, 'duration_months' => $c->duration_months,
        ]]);
    }

    public function revoke(string $code): JsonResponse
    {
        $deleted = DB::table('certificates')->where('code', $code)->delete();
        abort_unless($deleted, 404);
        Audit::log('certificate.revoke', 'certificate', null, ['code' => $code]);

        return response()->json(['ok' => true]);
    }

    private function evaluate(StudyGroup $group, Settings $settings, ?array $onlyIds): array
    {
        $students = $group->students()->select('users.id', 'first_name', 'last_name')->orderBy('first_name')->get()
            ->when($onlyIds, fn ($c) => $c->whereIn('id', $onlyIds));
        $statuses = DB::table('attendances')->join('class_sessions', 'class_sessions.id', '=', 'attendances.session_id')
            ->where('class_sessions.group_id', $group->id)->where('class_sessions.cancelled', false)
            ->get(['attendances.student_id', 'attendances.status'])->groupBy('student_id');
        $finalQuizIds = DB::table('quizzes')->where('course_id', $group->course_id)->where('is_final', true)->pluck('id');
        $passed = DB::table('quiz_attempts')->whereIn('quiz_id', $finalQuizIds)->where('passed', true)->pluck('student_id')->flip();
        $existing = DB::table('certificates')->where('group_id', $group->id)->pluck('code', 'student_id');

        return $students->map(function ($s) use ($statuses, $passed, $existing, $settings, $finalQuizIds) {
            $marked = ($statuses[$s->id] ?? collect())->pluck('status')->all();
            $rate = Attendance::rate($marked);
            $quizPassed = $finalQuizIds->isEmpty() ? true : isset($passed[$s->id]);

            return [
                'student_id' => $s->id,
                'name' => trim($s->first_name.' '.$s->last_name),
                'attendance_rate' => $rate,
                'marked_sessions' => count($marked),
                'final_quiz_passed' => $quizPassed,
                'certificate' => $existing[$s->id] ?? null,
                'blockers' => CertificatePolicy::blockers($rate, $quizPassed,
                    (float) $settings->int('certificate.min_attendance_percent'),
                    $settings->bool('certificate.require_final_quiz'),
                    count($marked),
                    $settings->int('certificate.min_marked_sessions')),
            ];
        })->values()->all();
    }

    private function newCode(Settings $settings): string
    {
        $prefix = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', (string) $settings->get('certificate.code_prefix')));
        do {
            $code = ($prefix !== '' ? $prefix.'-' : '').now()->format('y').'-'.Str::upper(Str::random(8));
        } while (DB::table('certificates')->where('code', $code)->exists());

        return $code;
    }
}
