<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseAccessTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private int $lessonId;

    private int $quizId;

    private User $enrolled;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $teacher = User::factory()->role(Role::Teacher)->create();
        $this->enrolled = User::factory()->create();
        $this->outsider = User::factory()->create();

        Sanctum::actingAs($teacher);
        $this->course = Course::findOrFail($this->postJson('/v1/courses', ['title' => 'Python', 'published' => true])->json('course.id'));
        $m = $this->postJson("/v1/courses/{$this->course->id}/modules", ['title' => 'Kirish'])->json('module.id');
        $this->lessonId = $this->postJson("/v1/modules/$m/lessons", ['title' => '1-dars', 'body' => 'Maxfiy matn', 'published' => true])->json('lesson.id');
        $this->postJson("/v1/lessons/{$this->lessonId}/materials", ['type' => 'VIDEO', 'title' => 'V', 'url' => 'https://youtu.be/x']);
        $this->quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'Test', 'published' => true])->json('quiz.id');

        $g = StudyGroup::create(['name' => 'PY-1', 'course_id' => $this->course->id, 'branch_id' => Branch::create(['name' => 'B'])->id,
            'teacher_id' => $teacher->id, 'start_date' => '2026-10-01']);
        DB::table('group_members')->insert(['group_id' => $g->id, 'student_id' => $this->enrolled->id, 'joined_at' => now()]);
    }

    public function test_outsider_sees_outline_but_not_content(): void
    {
        Sanctum::actingAs($this->outsider);
        $this->getJson('/v1/courses')->assertOk()->assertJsonPath('data.0.access.granted', false);
        $this->getJson("/v1/courses/{$this->course->id}")->assertOk()
            ->assertJsonPath('course.modules.0.lessons.0.title', '1-dars')
            ->assertJsonPath('access.granted', false)
            ->assertJsonPath('access.contact', '@rootzero_x')
            ->assertJsonMissingPath('course.modules.0.lessons.0.body');

        $res = $this->getJson("/v1/lessons/{$this->lessonId}")->assertForbidden()
            ->assertJsonPath('code', 'COURSE_ACCESS_DENIED')->assertJsonPath('contact_url', 'https://t.me/rootzero_x');
        $this->assertStringContainsString('@rootzero_x', $res->json('message'));
        $this->assertStringNotContainsString('Maxfiy matn', $res->getContent());

        $this->getJson("/v1/courses/{$this->course->id}/quizzes")->assertOk()->assertJsonPath('data.0.locked', true);
        $this->getJson("/v1/quizzes/{$this->quizId}")->assertForbidden()->assertJsonPath('code', 'COURSE_ACCESS_DENIED');
        $this->postJson("/v1/quizzes/{$this->quizId}/attempts")->assertForbidden();
    }

    public function test_enrolled_student_opens_content(): void
    {
        Sanctum::actingAs($this->enrolled);
        $this->getJson('/v1/courses')->assertJsonPath('data.0.access.granted', true);
        $this->getJson("/v1/lessons/{$this->lessonId}")->assertOk()->assertJsonPath('lesson.body', 'Maxfiy matn')
            ->assertJsonPath('nav.total', 1)->assertJsonCount(1, 'lesson.materials');
        $this->getJson("/v1/courses/{$this->course->id}/quizzes")->assertJsonPath('data.0.locked', false);
    }

    public function test_leaving_group_revokes_access(): void
    {
        DB::table('group_members')->where('student_id', $this->enrolled->id)->update(['left_at' => now()]);
        Sanctum::actingAs($this->enrolled);
        $this->getJson("/v1/lessons/{$this->lessonId}")->assertForbidden();
    }

    public function test_texts_and_outline_visibility_come_from_settings(): void
    {
        app(Settings::class)->set([
            'course.access_contact' => '@admin_test',
            'course.access_denied_text' => '{course} uchun {contact} bilan bog\'laning',
            'course.show_outline_to_guests' => false,
        ], null);
        Sanctum::actingAs($this->outsider);
        $this->getJson("/v1/lessons/{$this->lessonId}")->assertJsonPath('message', "Python uchun @admin_test bilan bog'laning");
        $this->getJson("/v1/courses/{$this->course->id}")->assertOk()->assertJsonCount(0, 'course.modules');
    }
}
