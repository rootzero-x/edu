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

/**
 * courses.published — faqat ochiq katalog uchun. Kursning faol guruhidagilar (o'quvchi, o'qituvchi, muallif, admin)
 * e'lon qilinmagan kursni, uning e'lon qilingan darslari va testlarini ochadi; boshqalar — tushunarli xabar (404).
 */
class UnpublishedCourseTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private int $lessonId;

    private int $draftLessonId;

    private int $quizId;

    private int $draftQuizId;

    private User $teacher;

    private User $enrolled;

    private User $outsider;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->enrolled = User::factory()->create();
        $this->outsider = User::factory()->create();

        Sanctum::actingAs($this->teacher);
        $this->course = Course::findOrFail($this->postJson('/v1/courses', ['title' => 'Programming', 'published' => false])->json('course.id'));
        $m = $this->postJson("/v1/courses/{$this->course->id}/modules", ['title' => 'Kirish'])->json('module.id');
        $this->lessonId = $this->postJson("/v1/modules/$m/lessons", ['title' => '1-dars', 'body' => 'Matn', 'published' => true])->json('lesson.id');
        $this->draftLessonId = $this->postJson("/v1/modules/$m/lessons", ['title' => 'Qoralama', 'published' => false])->json('lesson.id');
        $this->quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'Test', 'published' => true])->json('quiz.id');
        $this->postJson("/v1/quizzes/{$this->quizId}/questions", ['type' => 'SINGLE', 'text' => '?', 'options' => [
            ['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]])->assertCreated();
        $this->draftQuizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'Yopiq test', 'published' => false])->json('quiz.id');

        $this->group = StudyGroup::create(['name' => 'Programming Language', 'course_id' => $this->course->id,
            'branch_id' => Branch::create(['name' => 'B'])->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01']);
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $this->enrolled->id, 'joined_at' => now()]);
    }

    public function test_enrolled_student_opens_unpublished_course_lessons_and_quizzes(): void
    {
        Sanctum::actingAs($this->enrolled);
        $this->getJson("/v1/courses/{$this->course->id}")->assertOk()
            ->assertJsonPath('access.granted', true)
            ->assertJsonCount(1, 'course.modules.0.lessons'); // qoralama dars ko'rinmaydi
        $this->getJson("/v1/lessons/{$this->lessonId}")->assertOk()->assertJsonPath('lesson.body', 'Matn');
        $this->getJson("/v1/courses/{$this->course->id}/quizzes")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.locked', false);
        $this->getJson("/v1/quizzes/{$this->quizId}")->assertOk()->assertJsonPath('can_take', true);
        $this->postJson("/v1/quizzes/{$this->quizId}/attempts")->assertOk();

        // Yopiq dars/test — "Topilmadi" emas, sabab va kursga qaytish uchun ma'lumot.
        $this->getJson("/v1/lessons/{$this->draftLessonId}")->assertNotFound()
            ->assertJsonPath('code', 'LESSON_UNPUBLISHED')->assertJsonPath('course.id', $this->course->id)
            ->assertJsonPath('title', 'Dars hozircha yopiq');
        $this->getJson("/v1/quizzes/{$this->draftQuizId}")->assertNotFound()->assertJsonPath('code', 'QUIZ_UNPUBLISHED');
        $this->postJson("/v1/quizzes/{$this->draftQuizId}/attempts")->assertNotFound()->assertJsonPath('code', 'QUIZ_UNPUBLISHED');
    }

    public function test_outsider_gets_clear_unpublished_message_with_contact(): void
    {
        app(Settings::class)->set(['course.unpublished_text' => '«{course}» yopiq, {contact} ga yozing'], null);
        Sanctum::actingAs($this->outsider);
        $this->getJson('/v1/courses')->assertOk()->assertJsonCount(0, 'data'); // katalogda yo'q
        foreach (["/v1/courses/{$this->course->id}", "/v1/lessons/{$this->lessonId}", "/v1/courses/{$this->course->id}/quizzes", "/v1/quizzes/{$this->quizId}"] as $url) {
            $this->getJson($url)->assertNotFound()
                ->assertJsonPath('code', 'COURSE_UNPUBLISHED')
                ->assertJsonPath('message', '«Programming» yopiq, @rootzero_x ga yozing')
                ->assertJsonPath('contact_url', 'https://t.me/rootzero_x')
                ->assertJsonPath('title', "Kurs hali e'lon qilinmagan");
        }
        $this->postJson("/v1/quizzes/{$this->quizId}/attempts")->assertNotFound()->assertJsonPath('code', 'COURSE_UNPUBLISHED');
    }

    public function test_student_who_left_or_group_closed_loses_access_to_unpublished_course(): void
    {
        DB::table('group_members')->where('student_id', $this->enrolled->id)->update(['left_at' => now()]);
        Sanctum::actingAs($this->enrolled);
        $this->getJson("/v1/courses/{$this->course->id}")->assertNotFound()->assertJsonPath('code', 'COURSE_UNPUBLISHED');

        DB::table('group_members')->where('student_id', $this->enrolled->id)->update(['left_at' => null]);
        $this->group->update(['active' => false]);
        $this->getJson("/v1/courses/{$this->course->id}")->assertNotFound()->assertJsonPath('code', 'COURSE_UNPUBLISHED');
    }

    public function test_group_teacher_and_author_open_it_even_without_course_manage(): void
    {
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        Sanctum::actingAs($admin);
        $perms = app(Settings::class)->get('roles.permissions');
        $perms['TEACHER'] = ['course.view', 'attendance.mark'];
        $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => $perms]])->assertOk();

        $other = User::factory()->role(Role::Teacher)->create();
        Sanctum::actingAs($this->teacher->fresh()); // muallif ham, guruh o'qituvchisi ham
        $this->getJson("/v1/courses/{$this->course->id}")->assertOk()->assertJsonPath('access.granted', true);
        $this->getJson("/v1/lessons/{$this->lessonId}")->assertOk();

        Sanctum::actingAs($other);
        $this->getJson("/v1/courses/{$this->course->id}")->assertNotFound()->assertJsonPath('code', 'COURSE_UNPUBLISHED');
        $this->group->update(['teacher_id' => $other->id]);
        $this->getJson("/v1/courses/{$this->course->id}")->assertOk()->assertJsonPath('access.granted', true);
        $this->getJson("/v1/lessons/{$this->lessonId}")->assertOk();
    }

    public function test_deleted_records_say_what_was_not_found(): void
    {
        Sanctum::actingAs($this->enrolled);
        $this->getJson('/v1/lessons/999999')->assertNotFound()->assertJsonPath('entity', 'Lesson')
            ->assertJsonPath('message', "Dars topilmadi — u o'chirilgan bo'lishi mumkin. Kurs sahifasiga qayting.");
        $this->getJson('/v1/quizzes/999999')->assertNotFound()->assertJsonPath('entity', 'Quiz');
        $this->getJson('/v1/courses/999999')->assertNotFound()->assertJsonPath('entity', 'Course');
        $this->getJson('/v1/sessions/999999/attendance')->assertNotFound()->assertJsonPath('entity', 'ClassSession');
        $this->getJson('/v1/groups/999999/sessions')->assertNotFound()->assertJsonPath('entity', 'StudyGroup');
        $this->postJson('/v1/announcements/999999/read')->assertNotFound()->assertJsonPath('entity', 'Announcement');
    }
}
