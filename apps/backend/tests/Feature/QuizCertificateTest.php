<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuizCertificateTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private Course $course;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create(['first_name' => 'Laylo']);
        $this->course = Course::create(['title' => 'Python', 'created_by' => $this->teacher->id, 'published' => true]);
        $this->group = StudyGroup::create(['name' => 'PY-1', 'course_id' => $this->course->id, 'branch_id' => Branch::create(['name' => 'B'])->id,
            'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01']);
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $this->student->id, 'joined_at' => now()]);
    }

    /** @return array{0:int,1:array<string,int>} quiz id va savol/variant id'lari */
    private function buildQuiz(array $extra = []): array
    {
        Sanctum::actingAs($this->teacher);
        $quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'Yakuniy', 'is_final' => true, 'published' => true] + $extra)
            ->assertCreated()->assertJsonPath('quiz.pass_percent', 60)->json('quiz.id');
        $single = $this->postJson("/v1/quizzes/$quizId/questions", ['type' => 'SINGLE', 'text' => '2+2?', 'points' => 2,
            'options' => [['text' => '3', 'is_correct' => false], ['text' => '4', 'is_correct' => true]]])->assertCreated()->json('question');
        $text = $this->postJson("/v1/quizzes/$quizId/questions", ['type' => 'TEXT', 'text' => 'Poytaxt?', 'points' => 3,
            'accepted_answers' => ['Toshkent', 'Tashkent']])->assertCreated()->json('question');
        $this->postJson("/v1/quizzes/$quizId/questions", ['type' => 'SINGLE', 'text' => 'x', 'options' => [
            ['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => true]]])->assertStatus(422);

        return [$quizId, ['single' => $single['id'], 'right' => collect($single['options'])->firstWhere('is_correct', true)['id'], 'text' => $text['id']]];
    }

    public function test_student_takes_quiz_without_seeing_answers_and_is_graded(): void
    {
        [$quizId, $ids] = $this->buildQuiz(['max_attempts' => 2]);

        Sanctum::actingAs($this->student);
        $this->getJson("/v1/quizzes/$quizId")->assertOk()->assertJsonPath('can_take', true)->assertJsonMissingPath('quiz.questions');
        $attempt = $this->postJson("/v1/quizzes/$quizId/attempts")->assertOk()->json('attempt');
        $this->assertStringNotContainsString('is_correct', json_encode($attempt));
        $this->assertStringNotContainsString('Tashkent', json_encode($attempt));
        // Ochiq urinish qayta so'ralsa o'sha qaytadi.
        $this->assertSame($attempt['id'], $this->postJson("/v1/quizzes/$quizId/attempts")->json('attempt.id'));

        $this->postJson("/v1/attempts/{$attempt['id']}/submit", ['answers' => [
            (string) $ids['single'] => [(string) $ids['right']], (string) $ids['text'] => [' toshkent '],
        ]])->assertOk()->assertJsonPath('result.score', 5)->assertJsonPath('result.passed', true);
        $this->postJson("/v1/attempts/{$attempt['id']}/submit", ['answers' => []])->assertStatus(422);

        $second = $this->postJson("/v1/quizzes/$quizId/attempts")->json('attempt.id');
        $this->postJson("/v1/attempts/$second/submit", ['answers' => []])->assertJsonPath('result.passed', false);
        $this->postJson("/v1/quizzes/$quizId/attempts")->assertStatus(422);
    }

    public function test_time_limit_zeroes_late_submission(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');
        [$quizId, $ids] = $this->buildQuiz(['time_limit_sec' => 60]);
        Sanctum::actingAs($this->student);
        $attempt = $this->postJson("/v1/quizzes/$quizId/attempts")->json('attempt.id');
        Carbon::setTestNow('2026-10-21 10:05:00');
        $this->postJson("/v1/attempts/$attempt/submit", ['answers' => [(string) $ids['single'] => [(string) $ids['right']]]])
            ->assertJsonPath('result.score', 0)->assertJsonPath('result.time_expired', true);
    }

    public function test_outsider_cannot_take_quiz(): void
    {
        [$quizId] = $this->buildQuiz();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/v1/quizzes/$quizId/attempts")->assertForbidden();
    }

    public function test_certificate_rules_issue_and_public_verify(): void
    {
        [$quizId, $ids] = $this->buildQuiz();
        $admin = User::factory()->role(Role::Admin)->create();
        Sanctum::actingAs($admin);
        // Yakuniy testdan o'tmagan → berilmaydi.
        $this->getJson("/v1/groups/{$this->group->id}/certificates")->assertJsonPath('data.0.blockers', ['NO_ATTENDANCE_DATA', 'QUIZ_NOT_PASSED']);
        $this->markPresent();

        Sanctum::actingAs($this->student);
        $a = $this->postJson("/v1/quizzes/$quizId/attempts")->json('attempt.id');
        $this->postJson("/v1/attempts/$a/submit", ['answers' => [(string) $ids['single'] => [(string) $ids['right']], (string) $ids['text'] => ['Tashkent']]]);

        Sanctum::actingAs($admin);
        $issued = $this->postJson("/v1/groups/{$this->group->id}/certificates")->assertOk()->json('issued');
        $this->assertCount(1, $issued);
        $this->assertMatchesRegularExpression('/^TISU-\d{2}-[A-Z0-9]{8}$/', $issued[0]['code']);
        $this->assertCount(0, $this->postJson("/v1/groups/{$this->group->id}/certificates")->json('issued'));

        $this->app['auth']->forgetGuards();
        $this->getJson('/v1/public/certificates/'.$issued[0]['code'])->assertOk()->assertJsonPath('certificate.name', 'Laylo '.$this->student->last_name)
            ->assertJsonPath('certificate.course', 'Python');
        $this->getJson('/v1/public/certificates/NOPE')->assertNotFound();

        Sanctum::actingAs($this->student);
        $this->getJson('/v1/certificates')->assertJsonCount(1, 'data');
    }

    private function markPresent(): void
    {
        $sid = DB::table('class_sessions')->insertGetId(['group_id' => $this->group->id, 'starts_at' => '2026-10-02 14:00:00',
            'ends_at' => '2026-10-02 15:00:00', 'cancelled' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $this->student->id, 'status' => 'PRESENT',
            'marked_by' => $this->teacher->id, 'marked_at' => now()]);
    }

    public function test_certificate_conditions_follow_settings(): void
    {
        $this->buildQuiz();
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->getJson("/v1/groups/{$this->group->id}/certificates")->assertJsonPath('data.0.blockers', ['NO_ATTENDANCE_DATA', 'QUIZ_NOT_PASSED']);
        app(Settings::class)->set(['certificate.require_final_quiz' => false, 'certificate.min_marked_sessions' => 0], null);
        $this->getJson("/v1/groups/{$this->group->id}/certificates")->assertJsonPath('data.0.blockers', []);
    }
}
