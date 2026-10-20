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
        // Boshqaruv ko'rinishi (yashirin testlar ham): admin, kurs muallifi yoki shu kursning o'qituvchisi.
        $manage = $user->hasPermission('quiz.manage') && ($user->isAdmin() || CourseController::isOwnOrTaught($user, $course));
        if (! $manage) {
            $access->assertOpen($user, $course);
        }
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
    public function show(Request $request, CourseAccess $access, Settings $settings, Quiz $quiz): JsonResponse
    {
        $user = $request->user();
        // Javob kaliti faqat admin va kurs muallifiga (boshqa o'qituvchilar — faqat o'z o'quvchilari natijalarini ko'radi).
        if ($user->hasPermission('quiz.manage') && $this->canSeeKey($user, $quiz)) {
            return response()->json(['quiz' => $quiz->load('questions.options', 'course:id,title,created_by'),
                'can_edit' => CourseController::canEdit($user, $quiz->course)]);
        }
        $this->assertQuizOpen($access, $user, $quiz);
        $attempts = QuizAttempt::where(['quiz_id' => $quiz->id, 'student_id' => $user->id])->orderByDesc('id')->get();
        $eligible = $this->eligible($user, $quiz);

        return response()->json([
            'quiz' => $quiz->only(['id', 'title', 'is_final', 'pass_percent', 'max_attempts', 'time_limit_sec', 'course_id']) + [
                'questions_count' => $quiz->questions()->count(),
                'course' => $quiz->course->only(['id', 'title']),
            ],
            'attempts' => $attempts->map(fn ($a) => $a->only(['id', 'started_at', 'submitted_at', 'score', 'max_score', 'percent', 'passed'])),
            'can_take' => $eligible,
            // Hozir urinish boshlash/davom ettirish mumkinmi (limit va "o'tgan bo'lsa qayta topshirish" sozlamasi hisobga olingan).
            'can_start' => $eligible && ($attempts->contains(fn ($a) => ! $a->submitted_at && ! $this->expired($quiz, $a))
                || ($attempts->count() < $quiz->max_attempts && ($settings->bool('quiz.allow_retake_after_pass') || ! $attempts->contains('passed', true)))),
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
        Audit::log('question.create', 'question', $question->id, ['quiz_id' => $quiz->id]);

        return response()->json(['question' => $question->load('options')], 201);
    }

    /**
     * Savolni tahrirlash. Variant id'lari barqaror: variantlar o'rnida yangilanadi (o'chirib qayta yaratilmaydi).
     * Test topshirilgan bo'lsa va o'zgarish natijaga ta'sir qilsa (variantlar soni, to'g'ri javob, ball, turi) —
     * eski savol arxivlanadi va yangi versiya yaratiladi: topshirilgan javoblar eski variantlarga bog'liqligicha qoladi.
     */
    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        abort_if($question->archived_at !== null, 409, "Bu savolning eski versiyasi. Sahifani yangilang.");
        CourseController::authorizeEdit($request->user(), $question->quiz->course);
        $data = $this->validateQuestion($request, $question->type);
        $attempted = QuizAttempt::where('quiz_id', $question->quiz_id)->exists();
        $options = $question->options()->orderBy('position')->orderBy('id')->get()->values();
        // Matnli javoblar: katta-kichik harf, bo'shliq va tartib farqi — o'zgarish emas (baholash ham shunday solishtiradi).
        $norm = fn (array $a) => collect($a)->map(fn ($v) => mb_strtolower(trim((string) $v)))->filter(fn ($v) => $v !== '')->unique()->sort()->values()->all();
        $gradingChanged = (isset($data['options']) && (count($data['options']) !== $options->count()
                || $options->map(fn ($o) => (bool) $o->is_correct)->all() !== array_map(fn ($o) => (bool) $o['is_correct'], array_values($data['options']))))
            || (isset($data['points']) && (int) $data['points'] !== (int) $question->points)
            || ($question->type === 'TEXT' && isset($data['accepted_answers']) && $norm($data['accepted_answers']) !== $norm((array) $question->accepted_answers));

        if ($attempted && $gradingChanged) {
            $new = DB::transaction(function () use ($question, $data, $options) {
                $question->forceFill(['archived_at' => now()])->save();
                $copy = $question->quiz->questions()->create([
                    'type' => $question->type, 'text' => $data['text'] ?? $question->text, 'points' => $data['points'] ?? $question->points,
                    'position' => $data['position'] ?? $question->position, 'replaces_id' => $question->id,
                    'accepted_answers' => $question->type === 'TEXT' ? array_values($data['accepted_answers'] ?? (array) $question->accepted_answers) : null,
                ]);
                $source = $data['options'] ?? $options->map(fn ($o) => ['text' => $o->text, 'is_correct' => (bool) $o->is_correct])->all();
                foreach (array_values($source) as $i => $o) {
                    $copy->options()->create(['text' => $o['text'], 'is_correct' => (bool) $o['is_correct'], 'position' => $i + 1]);
                }

                return $copy;
            });
            Audit::log('question.version', 'question', $new->id, ['quiz_id' => $question->quiz_id, 'replaces_id' => $question->id]);

            return response()->json(['question' => $new->load('options'), 'versioned' => true]);
        }

        DB::transaction(function () use ($question, $data, $options) {
            $question->update(array_filter([
                'text' => $data['text'] ?? null, 'points' => $data['points'] ?? null, 'position' => $data['position'] ?? null,
                'accepted_answers' => $question->type === 'TEXT' && isset($data['accepted_answers']) ? array_values($data['accepted_answers']) : null,
            ], fn ($v) => $v !== null));
            if (isset($data['options'])) {
                $incoming = array_values($data['options']);
                foreach ($incoming as $i => $o) {
                    $fields = ['text' => $o['text'], 'is_correct' => (bool) $o['is_correct'], 'position' => $i + 1];
                    isset($options[$i]) ? $options[$i]->update($fields) : $question->options()->create($fields);
                }
                // Ortiqcha variantlar (faqat hali topshirilmagan testda — aks holda yuqorida versiya yaratiladi).
                $options->slice(count($incoming))->each->delete();
            }
        });
        Audit::log('question.update', 'question', $question->id, ['quiz_id' => $question->quiz_id]);

        return response()->json(['question' => $question->fresh()->load('options'), 'versioned' => false]);
    }

    /** Topshirilgan testdagi savol o'chirilmaydi — arxivlanadi (yangi urinishlarda ko'rinmaydi, tarix saqlanadi). */
    public function destroyQuestion(Request $request, Question $question): JsonResponse
    {
        CourseController::authorizeEdit($request->user(), $question->quiz->course);
        if (QuizAttempt::where('quiz_id', $question->quiz_id)->exists()) {
            $question->forceFill(['archived_at' => now()])->save();
            Audit::log('question.archive', 'question', $question->id, ['quiz_id' => $question->quiz_id]);

            return response()->json(['ok' => true, 'archived' => true]);
        }
        $question->delete();
        Audit::log('question.delete', 'question', $question->id, ['quiz_id' => $question->quiz_id]);

        return response()->json(['ok' => true, 'archived' => false]);
    }

    public function results(Request $request, Quiz $quiz): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canSeeKey($user, $quiz) || $this->teachesCourse($user, $quiz), 403, 'Ruxsat yo\'q.');
        // Admin va kurs muallifi — hammasini; boshqa o'qituvchi — faqat o'z guruhlari o'quvchilarini.
        $limitToOwn = ! $user->isAdmin() && (int) $quiz->course->created_by !== (int) $user->id;
        $rows = QuizAttempt::where('quiz_id', $quiz->id)->whereNotNull('submitted_at')
            ->when($limitToOwn, fn ($q) => $q->whereIn('quiz_attempts.student_id', DB::table('group_members')
                ->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
                ->where('study_groups.teacher_id', $user->id)->where('study_groups.course_id', $quiz->course_id)
                ->select('group_members.student_id')))
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
        $this->assertQuizOpen($access, $user, $quiz);
        abort_unless($this->eligible($user, $quiz), 403, 'Bu test sizning guruhingiz kursiga tegishli emas.');

        $attempt = DB::transaction(function () use ($quiz, $user) {
            // Foydalanuvchi qatorini qulflaymiz: parallel so'rovlar urinishlar limitidan oshib ketmasin.
            User::whereKey($user->id)->lockForUpdate()->first();
            $open = QuizAttempt::where(['quiz_id' => $quiz->id, 'student_id' => $user->id])->whereNull('submitted_at')->lockForUpdate()->first();
            if ($open && ! $this->expired($quiz, $open)) {
                return $open;
            }
            $done = QuizAttempt::where(['quiz_id' => $quiz->id, 'student_id' => $user->id])->count();
            abort_if($done >= $quiz->max_attempts, 422, 'Urinishlar tugagan.');
            abort_if(! app(Settings::class)->bool('quiz.allow_retake_after_pass')
                && QuizAttempt::where(['quiz_id' => $quiz->id, 'student_id' => $user->id, 'passed' => true])->exists(),
                422, 'Siz bu testdan allaqachon o\'tgansiz.');

            return QuizAttempt::create(['quiz_id' => $quiz->id, 'student_id' => $user->id, 'started_at' => now()]);
        });

        return response()->json(['attempt' => $this->presentAttempt($quiz, $attempt)]);
    }

    public function submit(Request $request, Settings $settings, QuizAttempt $attempt): JsonResponse
    {
        $user = $request->user();
        abort_unless((int) $attempt->student_id === (int) $user->id, 404);
        abort_if($attempt->submitted_at, 422, 'Bu urinish allaqachon topshirilgan.');
        $data = $request->validate(['answers' => ['present', 'array', 'max:500'], 'answers.*' => ['array', 'max:50'], 'answers.*.*' => ['nullable', 'string', 'max:2000']]);

        $quiz = $attempt->quiz->load('questions.options');
        $blocker = QuizGrader::submitBlocker($quiz->max_attempts, $quiz->time_limit_sec, 0, $attempt->started_at, now(),
            $settings->int('quiz.submit_grace_seconds'));
        $answers = $blocker === 'TIME_EXPIRED' ? [] : array_map(fn ($a) => array_values(array_filter(array_map('strval', $a), fn ($x) => trim($x) !== '')), $data['answers']);

        $questions = $quiz->questions->mapWithKeys(fn ($q) => [(string) $q->id => $q->forGrader()])->all();
        $result = QuizGrader::grade($questions, $answers, $quiz->pass_percent);
        $updated = QuizAttempt::where('id', $attempt->id)->whereNull('submitted_at')->update([
            'submitted_at' => now(), 'answers' => json_encode($answers),
            'score' => $result['score'], 'max_score' => $result['max_score'], 'percent' => $result['percent'], 'passed' => $result['passed'],
        ]);
        abort_unless($updated, 422, 'Bu urinish allaqachon topshirilgan.');

        $review = null;
        // To'g'ri javoblar faqat testdan o'tganda yoki urinishlar tugaganda ko'rsatiladi —
        // aks holda bo'sh topshirib kalitni olish va qayta urinishda 100% olish mumkin bo'lardi.
        $attemptsUsed = QuizAttempt::where(['quiz_id' => $quiz->id, 'student_id' => $user->id])->count();
        if ($settings->bool('quiz.show_correct_answers') && ($result['passed'] || $attemptsUsed >= $quiz->max_attempts)) {
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

    /** Javob kaliti: admin yoki kurs muallifi. */
    private function canSeeKey(User $user, Quiz $quiz): bool
    {
        return $user->isAdmin() || (int) $quiz->course->created_by === (int) $user->id;
    }

    /** Shu kurs guruhining o'qituvchisi (natijalarni faqat o'z o'quvchilari bo'yicha ko'radi). */
    private function teachesCourse(User $user, Quiz $quiz): bool
    {
        return $user->hasPermission('quiz.manage')
            && DB::table('study_groups')->where(['course_id' => $quiz->course_id, 'teacher_id' => $user->id])->exists();
    }

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

    /** Kurs ochiq (e'lon qilingan yoki o'z guruhi kursi), test e'lon qilingan va kontentga huquq bor. */
    private function assertQuizOpen(CourseAccess $access, User $user, Quiz $quiz): void
    {
        $access->assertOpen($user, $quiz->course);
        if (! $quiz->published) {
            throw $access->unavailable('QUIZ_UNPUBLISHED', 'quiz.unavailable', $quiz->course);
        }
        $access->authorize($user, $quiz->course);
    }
}
