<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CourseTest extends TestCase
{
    use RefreshDatabase;

    private function as(Role $role): User
    {
        $u = User::factory()->role($role)->create();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_teacher_builds_course_tree(): void
    {
        $this->as(Role::Teacher);
        $courseId = $this->postJson('/v1/courses', ['title' => 'Ingliz tili A1', 'monthly_fee' => 500000])
            ->assertCreated()->json('course.id');
        $moduleId = $this->postJson("/v1/courses/$courseId/modules", ['title' => '1-modul'])
            ->assertCreated()->assertJsonPath('module.position', 1)->json('module.id');
        $lessonId = $this->postJson("/v1/modules/$moduleId/lessons", ['title' => 'Salomlashish', 'published' => true])
            ->assertCreated()->json('lesson.id');
        $this->postJson("/v1/lessons/$lessonId/materials", ['type' => 'VIDEO', 'title' => 'Video', 'url' => 'https://youtu.be/x'])
            ->assertCreated();
        $this->postJson("/v1/lessons/$lessonId/materials", ['type' => 'VIDEO', 'title' => 'URLsiz'])
            ->assertUnprocessable();

        $this->getJson("/v1/courses/$courseId")->assertOk()
            ->assertJsonPath('course.modules.0.lessons.0.title', 'Salomlashish');
        $this->getJson("/v1/lessons/$lessonId")->assertOk()->assertJsonCount(1, 'lesson.materials');
    }

    public function test_student_sees_only_published(): void
    {
        $teacher = $this->as(Role::Teacher);
        $hidden = $this->postJson('/v1/courses', ['title' => 'Yashirin'])->json('course.id');
        $open = $this->postJson('/v1/courses', ['title' => 'Ochiq', 'published' => true])->json('course.id');
        $m = $this->postJson("/v1/courses/$open/modules", ['title' => 'M'])->json('module.id');
        $draft = $this->postJson("/v1/modules/$m/lessons", ['title' => 'Qoralama'])->json('lesson.id');
        $this->postJson("/v1/modules/$m/lessons", ['title' => 'Tayyor', 'published' => true]);

        $this->as(Role::Student);
        $this->getJson('/v1/courses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Ochiq');
        $this->getJson("/v1/courses/$hidden")->assertNotFound();
        $this->getJson("/v1/courses/$open")->assertOk()->assertJsonCount(1, 'course.modules.0.lessons');
        $this->getJson("/v1/lessons/$draft")->assertNotFound();
        $this->postJson('/v1/courses', ['title' => 'X'])->assertForbidden();
    }

    public function test_teacher_cannot_edit_others_course_but_admin_can(): void
    {
        $this->as(Role::Teacher);
        $id = $this->postJson('/v1/courses', ['title' => 'A'])->json('course.id');

        $this->as(Role::Teacher);
        $this->patchJson("/v1/courses/$id", ['title' => 'B'])->assertForbidden();

        $this->as(Role::Admin);
        $this->patchJson("/v1/courses/$id", ['title' => 'B'])->assertOk()->assertJsonPath('course.title', 'B');
        $this->deleteJson("/v1/courses/$id")->assertOk();
    }
}
