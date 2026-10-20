<?php

namespace App\Http\Controllers\Api;

use App\Domain\QuizGrader;
use App\Models\Course;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Audit;
use App\Support\CourseAccess;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Random\Engine\Mt19937;
use Random\Randomizer;

class QuizController
{
    // ── Boshqarish (quiz.manage + kurs muallifi yoki admin) ─────

    public function index(Request $request, CourseAccess $access, Course $course): JsonResponse
    {
        $user = $request->user();
        $manage = $user->hasPermission('quiz.manage');
        abort_unless($manage || $course->published, 404);
        $granted = $manage || $access->granted($user, $course);
        $quizzes = $course->quizzes()
            ->when(! $manage, fn ($q) => $q->where('published', true))
            ->withCount('questions')->orderBy('id')->get();
        if (! $manage) {
            $mine = QuizAttempt::where('student_id', $user->id)->whereIn('quiz_id', $quizzes->pluck('id'))
                ->whereNotNull('submitted_at')->get()->groupBy('quiz_id');
            $quizzes->each(function ($q) use ($mine, $granted) {
                $q->setAttribute('locked', ! $granted);
                $a = $mine[$q->id] ?? collect();
                $q->setAttribute('my_attempts', $a->count());
                $q->setAttribute('my_best_percent', $a->max('percent'));
                $q->setAttribute('my_passed', (bool) $a->firstWhere('passed', true));
            });
        }

        return response()->json(['data' => $quizzes, 'access' => $granted ? ['granted' => true] : ['granted' => false] + $access->denial($course)]);
    }

    public function store(Request $request, Settings $settings, Course $course): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $course);
        $data = $request->validate($this->quizRules($course));
        $data += [
            'pass_percent' => $settings->int('quiz.default_pass_percent'),
            'max_attempts' => $settings->int('quiz.default_max_attempts'),
        ];
        $quiz = $course->quizzes()->create($data);
        Audit::log('quiz.create', 'quiz', $quiz->id, ['title' => $quiz->title]);

        return response()->json(['quiz' => $quiz->fresh()], 201);
    }

    /** Boshqaruvchi uchun to'liq (to'g'ri javoblar bilan). */
    public function show(Request $request, CourseAccess $access, Quiz $quiz): JsonResponse
    {
        $user = $request->user();
        if ($user->hasPermission('quiz.manage')) {
            return response()->json(['quiz' => $quiz->load('questions.options', 'course:id,title')]);
        }
        abort_unless($quiz->published && $quiz->course->published, 404);
        $access->authorize($user, $quiz->course);
        $attempts = QuizAttempt::where(['quiz_id' => $quiz->id, 'student_id' => $user->id])->orderByDesc('id')->get();

        return response()->json([
            'quiz' => $quiz->only(['id', 'title', 'is_final', 'pass_percent', 'max_attempts', 'time_limit_sec', 'course_id']) + [
                'questions_count' => $quiz->questions()->count(),
                'course' => $quiz->course->only(['id', 'title']),
            ],
            'attempts' => $attempts->map(fn ($a) => $a->only(['id', 'started_at', 'submitted_at', 'score', 'max_score', 'percent', 'passed'])),
            'can_take' => $this->eligible($user, $quiz),
        ]);
    }

    public function update(Request $request, Quiz $quiz): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $quiz->course);
        $data = $request->validate($this->quizRules($quiz->course, partial: true));
        $quiz->update($data);

        return response()->json(['quiz' => $quiz]);
    }

    public function destroy(Request $request, Quiz $quiz): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $quiz->course);
        abort_if(QuizAttempt::where('quiz_id', $quiz->id)->exists(), 409, 'Testni topshirganlar bor. O\'chirish o\'rniga yashiring.');
        $quiz->delete();
        Audit::log('quiz.delete', 'quiz', $quiz->id, ['title' => $quiz->title]);

        return response()->json(['ok' => true]);
    }

    public function storeQuestion(Request $request, Quiz $quiz): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $quiz->course);
        $data = $this->validateQuestion($request);
        $question = DB::transaction(function () use ($quiz, $data) {
            $q = $quiz->questions()->create([
                'type' => $data['type'], 'text' => $data['text'], 'points' => $data['points'] ?? 1,
                'position' => $data['position'] ?? ((int) $quiz->questions()->max('position') + 1),
                'accepted_answers' => $data['type'] === 'TEXT' ? array_values($data['accepted_answers']) : null,
            ]);
            foreach ($data['options'] ?? [] as $i => $o) {
                $q->options()->create(['text' => $o['text'], 'is_correct' => (bool) $o['is_correct'], 'position' => $i + 1]);
            }

            return $q;
        });

        return response()->json(['question' => $question->load('options')], 201);
    }

    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $question->quiz->course);
        $data = $this->validateQuestion($request, $question->type);
        DB::transaction(function () use ($question, $data) {
            $question->update(array_filter([
                'text' => $data['text'] ?? null, 'points' => $data['points'] ?? null, 'position' => $data['position'] ?? null,
                'accepted_answers' => $question->type === 'TEXT' && isset($data['accepted_answers']) ? array_values($data['accepted_answers']) : null,
            ], fn ($v) => $v !== null));
            if (isset($data['options'])) {
                $question->options()->delete();
                foreach ($data['options'] as $i => $o) {
                    $question->options()->create(['text' => $o['text'], 'is_correct' => (bool) $o['is_correct'], 'position' => $i + 1]);
                }
            }
        });

        return response()->json(['question' => $question->fresh()->load('options')]);
    }

    public function destroyQuestion(Request $request, Question $question): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $question->quiz->course);
        $question->delete();

        return response()->json(['ok' => true]);
    }

    public function results(Request $request, Quiz $quiz): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || (int) $quiz->course->created_by === (int) $user->id
            || DB::table('study_groups')->where(['course_id' => $quiz->course_id, 'teacher_id' => $user->id])->exists(), 403, 'Ruxsat yo\'q.');
        $rows = QuizAttempt::where('quiz_id', $quiz->id)->whereNotNull('submitted_at')
            ->join('users', 'users.id', '=', 'quiz_attempts.student_id')
            ->orderByDesc('quiz_attempts.percent')
            ->get(['quiz_attempts.*', 'users.first_name', 'users.last_name', 'users.phone']);

        return response()->json(['data' => $rows]);
    }

    // ── Topshirish (quiz.take) ───────────────────────────────

    /** Urinish boshlash (ochiq urinish bo'lsa o'shani qaytaradi). Savollar to'g'ri javobsiz. */
    public function start(Request $request, CourseAccess $access, Quiz $quiz): JsonResponse
    {
        $user = $request->user();
        abort_unless($quiz->published && $quiz->course->published, 404);
        $access->authorize($user, $quiz->course);
        abort_unless($this->eligible($user, $quiz), 403, 'Bu test sizning guruhingiz kursiga tegishli emas.');

        $attempt = DB::transaction(function () use ($quiz, $user) {
            $open = QuizAttempt::where(['quiz_id' => $quiz->id, 'student_id' => $user->id])->whereNull('submitted_at')->lockForUpdate()->first();
            if ($open && ! $this->expired($quiz, $open)) {
                return $open;
            }
            $done = QuizAttempt::where(['quiz_id' => $quiz->id, 'student_id' => $user->id])->count();
            abort_if($done >= $quiz->max_attempts, 422, 'Urinishlar tugagan.');

            return QuizAttempt::create(['quiz_id' => $quiz->id, 'student_id' => $user->id, 'started_at' => now()]);
        });

        return response()->json(['attempt' => $this->presentAttempt($quiz, $attempt)]);
    }

    public function submit(Request $request, Settings $settings, QuizAttempt $attempt): JsonResponse
    {
        $user = $request->user();
        abort_unless((int) $attempt->student_id === (int) $user->id, 404);
        abort_if($attempt->submitted_at, 422, 'Bu urinish allaqachon topshirilgan.');
        $data = $request->validate(['answers' => ['present', 'array'], 'answers.*' => ['array', 'max:50'], 'answers.*.*' => ['string', 'max:2000']]);

        $quiz = $attempt->quiz->load('questions.options');
        $blocker = QuizGrader::submitBlocker($quiz->max_attempts, $quiz->time_limit_sec, 0, $attempt->started_at, now(),
            $settings->int('quiz.submit_grace_seconds'));
        $answers = $blocker === 'TIME_EXPIRED' ? [] : array_map(fn ($a) => array_values(array_map('strval', $a)), $data['answers']);

        $questions = $quiz->questions->mapWithKeys(fn ($q) => [(string) $q->id => $q->forGrader()])->all();
        $result = QuizGrader::grade($questions, $answers, $quiz->pass_percent);
        $updated = QuizAttempt::where('id', $attempt->id)->whereNull('submitted_at')->update([
            'submitted_at' => now(), 'answers' => json_encode($answers),
            'score' => $result['score'], 'max_score' => $result['max_score'], 'percent' => $result['percent'], 'passed' => $result['passed'],
        ]);
        abort_unless($updated, 422, 'Bu urinish allaqachon topshirilgan.');

        $review = null;
        if ($settings->bool('quiz.show_correct_answers')) {
            $review = $quiz->questions->map(fn ($q) => [
                'question_id' => $q->id,
                'correct' => $q->forGrader()['correct'],
                'earned' => QuizGrader::scoreQuestion($q->forGrader(), $answers[(string) $q->id] ?? null),
                'points' => $q->points,
            ]);
        }

        return response()->json(['result' => $result + ['time_expired' => $blocker === 'TIME_EXPIRED'], 'review' => $review]);
    }

    // ── Yordamchilar ─────────────────────────────────────────

    /** O'quvchi shu kursning faol guruhida bo'lishi kerak. */
    private function eligible(User $user, Quiz $quiz): bool
    {
        return $user->hasPermission('quiz.take') && app(CourseAccess::class)->enrolledStudent($user, (int) $quiz->course_id);
    }

    private function expired(Quiz $quiz, QuizAttempt $a): bool
    {
        return $quiz->time_limit_sec !== null && $a->started_at->addSeconds($quiz->time_limit_sec)->isPast();
    }

    private function presentAttempt(Quiz $quiz, QuizAttempt $attempt): array
    {
        $questions = $quiz->questions()->with('options')->get()->map(fn ($q) => [
            'id' => $q->id, 'type' => $q->type, 'text' => $q->text, 'points' => $q->points,
            'options' => $q->options->map(fn ($o) => ['id' => $o->id, 'text' => $o->text])->values()->all(),
        ])->all();
        if ($quiz->shuffle) {
            // Har urinish uchun barqaror tartib (sahifa yangilansa o'zgarmaydi).
            $rnd = new Randomizer(new Mt19937(crc32('attempt:'.$attempt->id)));
            $questions = $rnd->shuffleArray($questions);
            foreach ($questions as &$q) {
                $q['options'] = $rnd->shuffleArray($q['options']);
            }
            unset($q);
        }

        return [
            'id' => $attempt->id,
            'started_at' => $attempt->started_at,
            'deadline' => $quiz->time_limit_sec ? $attempt->started_at->copy()->addSeconds($quiz->time_limit_sec) : null,
            'quiz' => $quiz->only(['id', 'title', 'time_limit_sec', 'pass_percent']),
            'questions' => $questions,
        ];
    }

    private function quizRules(Course $course, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$req, 'string', 'max:200'],
            'lesson_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lessons', 'id')->whereIn('module_id',
                DB::table('course_modules')->where('course_id', $course->id)->pluck('id')->all())],
            'is_final' => ['sometimes', 'boolean'],
            'pass_percent' => ['sometimes', 'integer', 'between:1,100'],
            'max_attempts' => ['sometimes', 'integer', 'between:1,100'],
            'time_limit_sec' => ['sometimes', 'nullable', 'integer', 'between:30,86400'],
            'shuffle' => ['sometimes', 'boolean'],
            'published' => ['sometimes', 'boolean'],
        ];
    }

    private function validateQuestion(Request $request, ?string $existingType = null): array
    {
        $partial = $existingType !== null;
        $data = $request->validate([
            'type' => $partial ? ['prohibited'] : ['required', Rule::in(['SINGLE', 'MULTIPLE', 'TEXT'])],
            'text' => [$partial ? 'sometimes' : 'required', 'string', 'max:5000'],
            'points' => ['sometimes', 'integer', 'between:1,100'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'options' => ['sometimes', 'array', 'min:2', 'max:12'],
            'options.*.text' => ['required', 'string', 'max:1000'],
            'options.*.is_correct' => ['required', 'boolean'],
            'accepted_answers' => ['sometimes', 'array', 'min:1', 'max:20'],
            'accepted_answers.*' => ['string', 'max:500'],
        ]);
        $type = $existingType ?? $data['type'];
        if ($type === 'TEXT') {
            abort_if(! $partial && empty($data['accepted_answers']), 422, 'Matnli savol uchun qabul qilinadigan javoblar kerak.');
        } elseif (! $partial || isset($data['options'])) {
            abort_if(empty($data['options']), 422, 'Variantlar kerak (kamida 2 ta).');
            $correct = count(array_filter($data['options'], fn ($o) => $o['is_correct']));
            abort_if($correct === 0, 422, 'Kamida bitta to\'g\'ri variant belgilang.');
            abort_if($type === 'SINGLE' && $correct !== 1, 422, 'Bitta javobli savolda faqat bitta to\'g\'ri variant bo\'ladi.');
        }

        return $data;
    }
}
