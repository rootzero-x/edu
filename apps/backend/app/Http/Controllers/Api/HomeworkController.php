<?php

namespace App\Http\Controllers\Api;

use App\Domain\Homework;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassSession;
use App\Models\StoredFile;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use App\Support\Uploads;
use App\Telegram\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Uyga vazifa: o'qituvchi (guruh o'qituvchisi yoki group.manage) beradi va baholaydi,
 * o'quvchi (guruhning hozirgi a'zosi) matn, havola yoki fayl bilan topshiradi.
 */
class HomeworkController
{
    public function __construct(private Settings $settings) {}

    // ── Ruxsatlar ────────────────────────────────────────────

    public static function canManage(User $user, StudyGroup $group): bool
    {
        return $user->hasPermission('group.manage')
            || ($user->hasPermission('attendance.mark') && (int) $group->teacher_id === (int) $user->id);
    }

    public static function isMember(int $userId, int $groupId): bool
    {
        return DB::table('group_members')->where(['group_id' => $groupId, 'student_id' => $userId])->whereNull('left_at')->exists();
    }

    private function assertEnabled(): void
    {
        abort_unless($this->settings->bool('homework.enabled'), 404, "Uyga vazifa bo'limi o'chirilgan.");
    }

    private function authorizeView(User $user, Assignment $a): bool
    {
        $manage = self::canManage($user, $a->group);
        abort_unless($manage || self::isMember($user->id, $a->group_id), 404);

        return $manage;
    }

    private function authorizeManage(User $user, Assignment $a): void
    {
        abort_unless(self::canManage($user, $a->group), 403, 'Faqat guruh o\'qituvchisi yoki administrator.');
    }

    // ── Ro'yxat va ko'rish ───────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate([
            'group_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:open,done,all'],
            'limit' => ['nullable', 'integer', 'between:1,300'],
        ]);
        $user = $request->user();
        $limit = $data['limit'] ?? 100;
        $now = now();

        // O'qituvchi/boshqaruvchi: o'zi boshqaradigan guruhlar; o'quvchi: a'zo bo'lgan guruhlar.
        $managed = GroupController::visible($user)
            ->when(! $user->hasPermission('group.manage'), fn ($q) => $q->where('teacher_id', $user->id)->when(! $user->hasPermission('attendance.mark'), fn ($w) => $w->whereRaw('1 = 0')))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $memberOf = DB::table('group_members')->where('student_id', $user->id)->whereNull('left_at')->pluck('group_id')->map(fn ($id) => (int) $id)->all();
        $mode = $managed ? 'teacher' : 'student';
        $groupIds = $mode === 'teacher' ? $managed : $memberOf;
        if (! empty($data['group_id'])) {
            $groupIds = array_values(array_intersect($groupIds, [(int) $data['group_id']]));
        }

        $rows = Assignment::query()->whereIn('group_id', $groupIds)
            ->with(['group:id,name,course_id', 'group.course:id,title', 'session:id,starts_at', 'lesson:id,title'])
            ->orderByRaw('case when due_at is null then 1 else 0 end')->orderByDesc('due_at')->orderByDesc('id')
            ->limit($limit)->get();
        $ids = $rows->pluck('id')->all();
        $files = StoredFile::where('owner_type', 'assignment')->whereIn('owner_id', $ids)->get()->groupBy('owner_id');

        if ($mode === 'teacher') {
            $stats = $this->stats($rows);
            $items = $rows->map(fn (Assignment $a) => $this->present($a, $files[$a->id] ?? collect()) + ['stats' => $stats[$a->id]]);
            if (($data['status'] ?? 'all') === 'open') {
                $items = $items->filter(fn ($i) => ! $i['due_at'] || Carbon::parse($i['due_at'])->isFuture() || $i['stats']['submitted'] > $i['stats']['graded']);
            }
        } else {
            $subs = AssignmentSubmission::whereIn('assignment_id', $ids)->where('student_id', $user->id)->get()->keyBy('assignment_id');
            $items = $rows->map(function (Assignment $a) use ($subs, $files, $now) {
                $s = $subs[$a->id] ?? null;

                return $this->present($a, $files[$a->id] ?? collect()) + ['my' => $this->presentMine($a, $s, $now)];
            });
            $status = $data['status'] ?? 'all';
            if ($status !== 'all') {
                $open = [Homework::PENDING, Homework::MISSED];
                $items = $items->filter(fn ($i) => in_array($i['my']['status'], $open, true) === ($status === 'open'));
            }
        }

        return response()->json(['mode' => $mode, 'data' => $items->values(), 'scale' => $this->scale()]);
    }

    public function show(Request $request, Assignment $assignment): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        $assignment->load(['group:id,name,course_id,teacher_id', 'group.course:id,title', 'session:id,starts_at', 'lesson:id,title', 'author:id,first_name,last_name']);
        $manage = $this->authorizeView($user, $assignment);
        $files = StoredFile::where('owner_type', 'assignment')->where('owner_id', $assignment->id)->get();
        $out = $this->present($assignment, $files) + ['can_manage' => $manage];

        if ($manage) {
            $out['stats'] = $this->stats(collect([$assignment]))[$assignment->id];
            $out['submissions'] = $this->submissionRows($assignment);
        } else {
            $sub = AssignmentSubmission::where(['assignment_id' => $assignment->id, 'student_id' => $user->id])->first();
            $out['my'] = $this->presentMine($assignment, $sub, now(), withFiles: true);
        }

        return response()->json(['assignment' => $out, 'scale' => $this->scale()]);
    }

    // ── O'qituvchi: vazifa berish ────────────────────────────

    public function store(Request $request, Notifier $notifier, StudyGroup $group): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        abort_unless(self::canManage($user, $group), 403, 'Faqat guruh o\'qituvchisi yoki administrator.');
        $data = $this->validated($request, $group);
        $a = Assignment::create($data + ['group_id' => $group->id, 'created_by' => $user->id]);
        Audit::log('assignment.create', 'assignment', $a->id, ['after' => $a->only(['group_id', 'title', 'due_at', 'session_id', 'lesson_id', 'max_score'])]);

        if ($this->settings->bool('homework.notify_new') && $request->boolean('notify', true)) {
            $this->notifyNew($notifier, $a, $group);
        }

        return response()->json(['assignment' => $this->present($a->load(['group.course', 'session', 'lesson']), collect())], 201);
    }

    public function update(Request $request, Assignment $assignment): JsonResponse
    {
        $this->assertEnabled();
        $this->authorizeManage($request->user(), $assignment);
        $data = $this->validated($request, $assignment->group, partial: true);
        $before = $assignment->only(array_keys($data));
        $assignment->update($data);
        // Muddat uzaytirilsa, o'qituvchi natijasi qayta yuboriladi.
        if (array_key_exists('due_at', $data) && $assignment->due_at && $assignment->due_at->isFuture()) {
            $assignment->update(['summary_sent_at' => null]);
        }
        Audit::log('assignment.update', 'assignment', $assignment->id, ['before' => self::plain($before), 'after' => self::plain($assignment->only(array_keys($data)))]);

        return response()->json(['assignment' => $this->present($assignment->fresh(['group.course', 'session', 'lesson']),
            StoredFile::where('owner_type', 'assignment')->where('owner_id', $assignment->id)->get())]);
    }

    public function destroy(Request $request, Uploads $uploads, Assignment $assignment): JsonResponse
    {
        $this->assertEnabled();
        $this->authorizeManage($request->user(), $assignment);
        abort_if(AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where(fn ($w) => $w->whereNotNull('submitted_at')->orWhereNotNull('score'))->exists(), 409,
            "Bu vazifaga javoblar yoki baholar bor — o'chirib bo'lmaydi.");
        DB::transaction(function () use ($assignment, $uploads) {
            $subIds = AssignmentSubmission::where('assignment_id', $assignment->id)->pluck('id')->all();
            $uploads->deleteOwned('submission', $subIds);
            $uploads->deleteOwned('assignment', [$assignment->id]);
            $assignment->delete();
        });
        Audit::log('assignment.delete', 'assignment', $assignment->id, ['before' => ['title' => $assignment->title, 'group_id' => $assignment->group_id]]);

        return response()->json(['ok' => true]);
    }

    public function uploadFile(Request $request, Uploads $uploads, Assignment $assignment): JsonResponse
    {
        $this->assertEnabled();
        $this->authorizeManage($request->user(), $assignment);
        $request->validate(['file' => ['required', 'file']]);
        $this->assertFileLimit('assignment', $assignment->id);
        $file = $uploads->store($request->file('file'), 'assignment', $assignment->id, $request->user()->id);
        Audit::log('file.upload', 'assignment', $assignment->id, ['file_id' => $file->id, 'name' => $file->original_name, 'size' => $file->size]);

        return response()->json(['file' => $file->toApi()], 201);
    }

    // ── O'qituvchi: baholash ─────────────────────────────────

    public function grade(Request $request, Notifier $notifier, Assignment $assignment): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        $this->authorizeManage($user, $assignment);
        $max = $this->maxScore($assignment);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.student_id' => ['required', 'integer'],
            'items.*.score' => ['nullable', 'integer', 'min:0', "max:$max"],
            'items.*.feedback' => ['nullable', 'string', 'max:2000'],
        ]);
        $members = DB::table('group_members')->where('group_id', $assignment->group_id)->whereNull('left_at')->pluck('student_id')->map(fn ($id) => (int) $id)->all();
        $existing = AssignmentSubmission::where('assignment_id', $assignment->id)->get()->keyBy('student_id');

        $changes = [];
        DB::transaction(function () use ($data, $members, $existing, $assignment, $user, &$changes) {
            foreach ($data['items'] as $item) {
                $sid = (int) $item['student_id'];
                $row = $existing[$sid] ?? null;
                abort_unless($row || in_array($sid, $members, true), 422, "O'quvchi #$sid bu guruhda emas.");
                $score = array_key_exists('score', $item) ? $item['score'] : $row?->score;
                $feedback = array_key_exists('feedback', $item) ? $item['feedback'] : $row?->feedback;
                if ($row && $row->score === $score && $row->feedback === $feedback) {
                    continue;
                }
                $row ??= new AssignmentSubmission(['assignment_id' => $assignment->id, 'student_id' => $sid]);
                $changes[] = ['student_id' => $sid, 'from' => $row->score, 'to' => $score];
                $row->fill([
                    'score' => $score, 'feedback' => $feedback,
                    'graded_by' => $score === null ? null : $user->id, 'graded_at' => $score === null ? null : now(),
                ])->save();
            }
        });
        if ($changes) {
            Audit::log('assignment.grade', 'assignment', $assignment->id, ['changes' => $changes]);
        }
        $graded = array_values(array_filter($changes, fn ($c) => $c['to'] !== null));
        if ($graded && $this->settings->bool('homework.notify_graded')) {
            $this->notifyGraded($notifier, $assignment, $graded, $max);
        }

        return response()->json(['ok' => true, 'changed' => count($changes), 'submissions' => $this->submissionRows($assignment)]);
    }

    // ── O'quvchi: topshirish ─────────────────────────────────

    public function submit(Request $request, Assignment $assignment): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        abort_unless(self::isMember($user->id, $assignment->group_id), 403, "Siz bu guruh a'zosi emassiz.");
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
            'link_url' => ['nullable', 'url:http,https', 'max:2000'],
        ]);
        $sub = AssignmentSubmission::firstOrNew(['assignment_id' => $assignment->id, 'student_id' => $user->id]);
        $this->assertCanChange($assignment, $sub);
        $hasFiles = $sub->exists && StoredFile::where('owner_type', 'submission')->where('owner_id', $sub->id)->exists();
        if (blank($data['body'] ?? null) && blank($data['link_url'] ?? null) && ! $hasFiles) {
            throw ValidationException::withMessages(['body' => "Javob matni, havola yoki fayl kerak."]);
        }
        $sub->fill(['body' => $data['body'] ?? null, 'link_url' => $data['link_url'] ?? null, 'submitted_at' => now()])->save();

        return response()->json(['my' => $this->presentMine($assignment, $sub->fresh(), now(), withFiles: true)]);
    }

    public function uploadSubmissionFile(Request $request, Uploads $uploads, Assignment $assignment): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        abort_unless(self::isMember($user->id, $assignment->group_id), 403, "Siz bu guruh a'zosi emassiz.");
        abort_unless($this->settings->bool('uploads.student_files'), 403, "Javobga fayl biriktirish o'chirilgan.");
        $request->validate(['file' => ['required', 'file']]);
        $sub = AssignmentSubmission::firstOrCreate(['assignment_id' => $assignment->id, 'student_id' => $user->id]);
        $this->assertCanChange($assignment, $sub);
        $this->assertFileLimit('submission', $sub->id);
        $file = $uploads->store($request->file('file'), 'submission', $sub->id, $user->id);

        return response()->json(['file' => $file->toApi()], 201);
    }

    /** O'quvchining o'z baholari: mashg'ulot baholari va baholangan vazifalar. */
    public function myMarks(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['group_id' => ['nullable', 'integer'], 'limit' => ['nullable', 'integer', 'between:1,500']]);
        $limit = $data['limit'] ?? 100;
        $max = $this->settings->int('marks.scale_max');
        $sessionMarks = DB::table('session_marks')
            ->join('class_sessions', 'class_sessions.id', '=', 'session_marks.session_id')
            ->join('study_groups', 'study_groups.id', '=', 'class_sessions.group_id')
            ->where('session_marks.student_id', $user->id)
            ->when($data['group_id'] ?? null, fn ($q, $g) => $q->where('class_sessions.group_id', $g))
            ->orderByDesc('class_sessions.starts_at')->limit($limit)
            ->get(['session_marks.score', 'session_marks.note', 'class_sessions.id as session_id', 'class_sessions.starts_at', 'class_sessions.topic',
                'study_groups.id as group_id', 'study_groups.name as group_name'])
            ->map(fn ($r) => ['type' => 'session', 'session_id' => (int) $r->session_id, 'group_id' => (int) $r->group_id, 'group_name' => $r->group_name,
                'date' => Carbon::parse($r->starts_at)->toIso8601String(), 'title' => $r->topic, 'score' => (int) $r->score, 'max' => $max,
                'percent' => Homework::percent((int) $r->score, $max), 'note' => $r->note]);
        $homework = DB::table('assignment_submissions')
            ->join('assignments', 'assignments.id', '=', 'assignment_submissions.assignment_id')
            ->join('study_groups', 'study_groups.id', '=', 'assignments.group_id')
            ->where('assignment_submissions.student_id', $user->id)->whereNotNull('assignment_submissions.score')
            ->when($data['group_id'] ?? null, fn ($q, $g) => $q->where('assignments.group_id', $g))
            ->orderByDesc('assignment_submissions.graded_at')->limit($limit)
            ->get(['assignment_submissions.score', 'assignment_submissions.feedback', 'assignment_submissions.graded_at', 'assignments.id as assignment_id',
                'assignments.title', 'assignments.max_score', 'study_groups.id as group_id', 'study_groups.name as group_name'])
            ->map(fn ($r) => ['type' => 'homework', 'assignment_id' => (int) $r->assignment_id, 'group_id' => (int) $r->group_id, 'group_name' => $r->group_name,
                'date' => Carbon::parse($r->graded_at)->toIso8601String(), 'title' => $r->title, 'score' => (int) $r->score,
                'max' => (int) ($r->max_score ?: $max), 'percent' => Homework::percent((int) $r->score, (int) ($r->max_score ?: $max)), 'note' => $r->feedback]);
        $all = $sessionMarks->concat($homework)->sortByDesc('date')->values()->take($limit)->values();

        return response()->json([
            'data' => $all,
            'average_percent' => $all->count() ? round($all->avg('percent'), 1) : null,
            'scale' => $this->scale(),
        ]);
    }

    // ── Yordamchilar ─────────────────────────────────────────

    private function validated(Request $request, StudyGroup $group, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $scaleMax = 1000;
        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'session_id' => ['sometimes', 'nullable', 'integer'],
            'lesson_id' => ['sometimes', 'nullable', 'integer'],
            'max_score' => ['sometimes', 'nullable', 'integer', 'min:1', "max:$scaleMax"],
        ]);
        if (! empty($data['session_id'])) {
            abort_unless(ClassSession::where('id', $data['session_id'])->where('group_id', $group->id)->exists(), 422, "Mashg'ulot bu guruhga tegishli emas.");
        }
        if (! empty($data['lesson_id'])) {
            abort_unless(DB::table('lessons')->join('course_modules', 'course_modules.id', '=', 'lessons.module_id')
                ->where('lessons.id', $data['lesson_id'])->where('course_modules.course_id', $group->course_id)->exists(), 422, "Dars guruh kursiga tegishli emas.");
        }
        if (array_key_exists('due_at', $data) && $data['due_at']) {
            // Frontend ISO (UTC "Z") yuboradi — ilova vaqt zonasida saqlanadi.
            $data['due_at'] = Carbon::parse($data['due_at'])->setTimezone(config('app.timezone'));
        }

        return $data;
    }

    private function assertCanChange(Assignment $a, AssignmentSubmission $sub): void
    {
        abort_if($sub->score !== null, 409, 'Vazifa baholangan — javobni o\'zgartirib bo\'lmaydi.');
        abort_if($sub->submitted_at && ! $this->settings->bool('homework.resubmit_until_graded'), 409, 'Javob allaqachon topshirilgan.');
        abort_if($a->due_at && $a->due_at->isPast() && ! $this->settings->bool('homework.allow_late'), 422, "Topshirish muddati o'tgan.");
    }

    private function assertFileLimit(string $ownerType, int $ownerId): void
    {
        $max = $this->settings->int('uploads.max_files_per_item');
        abort_if(StoredFile::where('owner_type', $ownerType)->where('owner_id', $ownerId)->count() >= $max, 422, "Ko'pi bilan $max ta fayl biriktirish mumkin.");
    }

    private function maxScore(Assignment $a): int
    {
        return $a->max_score ?: $this->settings->int('marks.scale_max');
    }

    private function scale(): array
    {
        return ['min' => $this->settings->int('marks.scale_min'), 'max' => $this->settings->int('marks.scale_max')];
    }

    /** @return array<int, array{total:int, submitted:int, graded:int, late:int}> */
    private function stats(Collection $assignments): array
    {
        $groupIds = $assignments->pluck('group_id')->unique()->all();
        $members = DB::table('group_members')->whereIn('group_id', $groupIds)->whereNull('left_at')->get(['group_id', 'student_id'])
            ->groupBy('group_id')->map(fn ($c) => $c->pluck('student_id')->map(fn ($id) => (int) $id)->all());
        $subs = AssignmentSubmission::whereIn('assignment_id', $assignments->pluck('id'))->get(['assignment_id', 'student_id', 'submitted_at', 'score'])->groupBy('assignment_id');
        $out = [];
        foreach ($assignments as $a) {
            $ids = $members[$a->group_id] ?? [];
            $rows = ($subs[$a->id] ?? collect())->filter(fn ($s) => in_array((int) $s->student_id, $ids, true));
            $out[$a->id] = [
                'total' => count($ids),
                'submitted' => $rows->whereNotNull('submitted_at')->count(),
                'graded' => $rows->whereNotNull('score')->count(),
                'late' => $a->due_at ? $rows->filter(fn ($s) => $s->submitted_at && $s->submitted_at->greaterThan($a->due_at))->count() : 0,
            ];
        }

        return $out;
    }

    private function submissionRows(Assignment $a): Collection
    {
        $now = now();
        $members = DB::table('group_members')->join('users', 'users.id', '=', 'group_members.student_id')
            ->where('group_members.group_id', $a->group_id)->whereNull('group_members.left_at')
            ->orderBy('users.first_name')->orderBy('users.last_name')
            ->get(['users.id', 'users.first_name', 'users.last_name']);
        $subs = AssignmentSubmission::where('assignment_id', $a->id)->get()->keyBy('student_id');
        // Guruhdan chiqqan, lekin javob/bahosi bor o'quvchilar ham ko'rinadi.
        $left = User::whereIn('id', $subs->keys()->diff($members->pluck('id')))->get(['id', 'first_name', 'last_name']);
        $files = StoredFile::where('owner_type', 'submission')->whereIn('owner_id', $subs->pluck('id'))->get()->groupBy('owner_id');

        return $members->map(fn ($m) => [$m, false])->concat($left->map(fn ($m) => [$m, true]))
            ->map(function ($pair) use ($subs, $files, $a, $now) {
                [$m, $isLeft] = $pair;
                $s = $subs[$m->id] ?? null;

                return [
                    'student_id' => (int) $m->id,
                    'name' => trim($m->first_name.' '.$m->last_name),
                    'left_group' => $isLeft,
                    'status' => Homework::status($s?->submitted_at, $s?->score, $a->due_at, $now),
                    'submitted_at' => $s?->submitted_at?->toIso8601String(),
                    'body' => $s?->body,
                    'link_url' => $s?->link_url,
                    'score' => $s?->score,
                    'feedback' => $s?->feedback,
                    'graded_at' => $s?->graded_at?->toIso8601String(),
                    'files' => $s ? ($files[$s->id] ?? collect())->map->toApi()->values() : [],
                ];
            })->values();
    }

    private function present(Assignment $a, Collection $files): array
    {
        return [
            'id' => $a->id,
            'group_id' => $a->group_id,
            'group_name' => $a->group?->name,
            'course_title' => $a->group?->course?->title,
            'session_id' => $a->session_id,
            'session_starts_at' => $a->session?->starts_at?->toIso8601String(),
            'lesson_id' => $a->lesson_id,
            'lesson_title' => $a->lesson?->title,
            'title' => $a->title,
            'body' => $a->body,
            'due_at' => $a->due_at?->toIso8601String(),
            'max_score' => $this->maxScore($a),
            'author' => $a->relationLoaded('author') && $a->author ? trim($a->author->first_name.' '.$a->author->last_name) : null,
            'created_at' => $a->created_at?->toIso8601String(),
            'files' => $files->map->toApi()->values(),
        ];
    }

    private function presentMine(Assignment $a, ?AssignmentSubmission $s, Carbon $now, bool $withFiles = false): array
    {
        $status = Homework::status($s?->submitted_at, $s?->score, $a->due_at, $now);
        $locked = $s?->score !== null
            || ($s?->submitted_at && ! $this->settings->bool('homework.resubmit_until_graded'))
            || ($a->due_at && $a->due_at->lessThan($now) && ! $this->settings->bool('homework.allow_late'));

        return [
            'status' => $status,
            'submitted_at' => $s?->submitted_at?->toIso8601String(),
            'body' => $s?->body,
            'link_url' => $s?->link_url,
            'score' => $s?->score,
            'feedback' => $s?->feedback,
            'graded_at' => $s?->graded_at?->toIso8601String(),
            'can_submit' => ! $locked,
            'files' => $withFiles && $s ? StoredFile::where('owner_type', 'submission')->where('owner_id', $s->id)->get()->map->toApi()->values() : [],
        ];
    }

    private function url(Assignment $a): string
    {
        return rtrim((string) $this->settings->get('site.url'), '/').'/dashboard/homework/'.$a->id;
    }

    private function notifyNew(Notifier $notifier, Assignment $a, StudyGroup $group): void
    {
        $students = DB::table('users')->join('group_members', 'group_members.student_id', '=', 'users.id')
            ->where('group_members.group_id', $group->id)->whereNull('group_members.left_at')->where('users.active', true)
            ->get(['users.id', 'users.first_name']);
        $vars = ['group' => $group->name, 'title' => $a->title, 'due' => $a->due_at?->format('d.m.Y H:i') ?? '—', 'url' => $this->url($a)];
        $id = $a->id;
        defer(function () use ($notifier, $students, $vars, $id) {
            foreach ($students as $s) {
                $notifier->sendTemplate((int) $s->id, "homework.new:$id", 'telegram.text.homework_new', $vars + ['name' => $s->first_name]);
            }
        });
    }

    private function notifyGraded(Notifier $notifier, Assignment $a, array $graded, int $max): void
    {
        $names = DB::table('users')->whereIn('id', array_column($graded, 'student_id'))->where('active', true)->pluck('first_name', 'id');
        $feedback = AssignmentSubmission::where('assignment_id', $a->id)->whereIn('student_id', array_column($graded, 'student_id'))->pluck('feedback', 'student_id');
        $url = $this->url($a);
        defer(function () use ($notifier, $a, $graded, $names, $feedback, $max, $url) {
            foreach ($graded as $g) {
                if (! isset($names[$g['student_id']])) {
                    continue;
                }
                $notifier->sendTemplate($g['student_id'], "homework.graded:{$a->id}", 'telegram.text.homework_graded', [
                    'name' => $names[$g['student_id']], 'title' => $a->title, 'score' => $g['to'], 'max' => $max,
                    'feedback' => (string) ($feedback[$g['student_id']] ?? ''), 'url' => $url,
                ]);
            }
        });
    }

    private static function plain(array $v): array
    {
        return array_map(fn ($x) => $x instanceof \DateTimeInterface ? $x->format('Y-m-d H:i') : $x, $v);
    }
}
