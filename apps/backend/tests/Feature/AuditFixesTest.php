<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Audit natijasida tuzatilgan holatlar. */
class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $classmate;

    private Course $course;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 16:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create();
        $this->classmate = User::factory()->create(['phone' => '+998909999999']);
        $this->course = Course::create(['title' => 'K', 'created_by' => $this->teacher->id, 'published' => true]);
        $this->group = StudyGroup::create(['name' => 'G', 'course_id' => $this->course->id, 'branch_id' => Branch::create(['name' => 'B'])->id,
            'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01']);
        foreach ([$this->student, $this->classmate] as $u) {
            DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $u->id, 'joined_at' => now()]);
        }
    }

    public function test_answer_key_hidden_until_passed_or_attempts_used(): void
    {
        Sanctum::actingAs($this->teacher);
        $quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'T', 'published' => true, 'max_attempts' => 2])->json('quiz.id');
        $q = $this->postJson("/v1/quizzes/$quizId/questions", ['type' => 'SINGLE', 'text' => '?', 'options' => [
            ['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]])->json('question');
        $right = (string) collect($q['options'])->firstWhere('is_correct', true)['id'];

        Sanctum::actingAs($this->student);
        $a1 = $this->postJson("/v1/quizzes/$quizId/attempts")->json('attempt.id');
        $this->postJson("/v1/attempts/$a1/submit", ['answers' => []])->assertJsonPath('result.passed', false)->assertJsonPath('review', null);
        $a2 = $this->postJson("/v1/quizzes/$quizId/attempts")->json('attempt.id');
        $this->postJson("/v1/attempts/$a2/submit", ['answers' => [(string) $q['id'] => [$right]]])
            ->assertJsonPath('result.passed', true)->assertJsonPath('review.0.correct', [$right]);
    }

    public function test_only_related_staff_see_answer_key_and_results(): void
    {
        Sanctum::actingAs($this->teacher);
        $quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'T', 'published' => true])->json('quiz.id');
        $this->getJson("/v1/quizzes/$quizId")->assertJsonStructure(['quiz' => ['questions']]);

        Sanctum::actingAs(User::factory()->role(Role::Teacher)->create());
        $this->getJson("/v1/quizzes/$quizId")->assertOk()->assertJsonMissingPath('quiz.questions');
        $this->getJson("/v1/quizzes/$quizId/results")->assertForbidden();
    }

    public function test_session_time_change_is_admin_only_and_timezone_safe(): void
    {
        $sid = DB::table('class_sessions')->insertGetId(['group_id' => $this->group->id, 'starts_at' => '2026-10-10 14:00:00',
            'ends_at' => '2026-10-10 15:00:00', 'cancelled' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_sessions')->insert(['group_id' => $this->group->id, 'starts_at' => '2026-10-12 14:00:00',
            'ends_at' => '2026-10-12 15:00:00', 'cancelled' => false, 'created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($this->teacher);
        $this->patchJson("/v1/sessions/$sid", ['starts_at' => '2026-10-21T09:00:00Z', 'ends_at' => '2026-10-21T10:00:00Z'])->assertForbidden();
        $this->patchJson("/v1/sessions/$sid", ['topic' => 'Mavzu'])->assertOk();

        Sanctum::actingAs($this->admin);
        // 09:00Z = 14:00 Toshkent — mahalliy vaqt sifatida saqlanadi.
        $this->patchJson("/v1/sessions/$sid", ['starts_at' => '2026-10-11T09:00:00Z', 'ends_at' => '2026-10-11T10:00:00Z'])->assertOk();
        $this->assertSame('2026-10-11 14:00:00', (string) DB::table('class_sessions')->where('id', $sid)->value('starts_at'));
        $this->patchJson("/v1/sessions/$sid", ['starts_at' => '2026-10-12T09:00:00Z', 'ends_at' => '2026-10-12T10:00:00Z'])->assertUnprocessable();
    }

    public function test_classmates_phone_hidden_from_students(): void
    {
        Sanctum::actingAs($this->student);
        $res = $this->getJson("/v1/groups/{$this->group->id}")->assertOk();
        $this->assertStringNotContainsString('+998909999999', $res->getContent());

        Sanctum::actingAs($this->teacher);
        $this->assertStringContainsString('+998909999999', $this->getJson("/v1/groups/{$this->group->id}")->getContent());
    }

    public function test_user_filters(): void
    {
        User::factory()->role(Role::Teacher)->create(['active' => false]);

        Sanctum::actingAs($this->admin);
        $this->getJson('/v1/users?role=TEACHER&active=1&per_page=500')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/v1/users?per_page=9999')->assertUnprocessable();
    }

    public function test_certificate_preview_validates_input(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson("/v1/groups/{$this->group->id}/certificates?student_ids=abc")->assertUnprocessable();
    }
}
