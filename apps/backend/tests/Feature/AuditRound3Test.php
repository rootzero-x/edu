<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Uchinchi audit: e'lon guruhi, adminlar ustidan nazorat, guruh sanalari/kursi, test savollari, sozlamalar. */
class AuditRound3Test extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private StudyGroup $own;

    private StudyGroup $foreign;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->course = Course::create(['title' => 'K', 'created_by' => $this->teacher->id, 'published' => true]);
        $b = Branch::create(['name' => 'B']);
        $this->own = StudyGroup::create(['name' => 'G1', 'course_id' => $this->course->id, 'branch_id' => $b->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01']);
        $this->foreign = StudyGroup::create(['name' => 'G2', 'course_id' => $this->course->id, 'branch_id' => $b->id, 'teacher_id' => $admin->id, 'start_date' => '2026-10-01']);
    }

    public function test_teacher_cannot_retarget_announcement_to_foreign_group(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->postJson('/v1/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'GROUP', 'group_id' => $this->own->id])
            ->assertCreated()->json('announcement.id');
        $this->patchJson("/v1/announcements/$id", ['group_id' => $this->foreign->id])->assertForbidden();
        $this->patchJson("/v1/announcements/$id", ['title' => 'yangi'])->assertOk();

        // Guruhdan olib tashlangan o'qituvchi eski e'lonni chop eta olmaydi.
        $this->own->update(['teacher_id' => $this->foreign->teacher_id]);
        $this->postJson("/v1/announcements/$id/publish", ['published' => true])->assertForbidden();
    }

    public function test_only_super_admin_manages_admins(): void
    {
        $a1 = User::factory()->role(Role::Admin)->create();
        $a2 = User::factory()->role(Role::Admin)->create();
        $teacher = User::factory()->role(Role::Teacher)->create();
        Sanctum::actingAs($a1);
        $this->patchJson("/v1/users/{$a2->id}", ['password' => 'takeover-pass-1'])->assertForbidden();
        $this->patchJson("/v1/users/{$teacher->id}", ['role' => 'ADMIN'])->assertForbidden();
        $this->postJson('/v1/users', ['phone' => '+998901112299', 'first_name' => 'X', 'role' => 'ADMIN'])->assertForbidden();
        $this->patchJson("/v1/users/{$a1->id}", ['first_name' => 'Men'])->assertOk();
        $this->patchJson("/v1/users/{$teacher->id}", ['first_name' => 'Ustoz'])->assertOk();

        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());
        $this->patchJson("/v1/users/{$a2->id}", ['active' => false])->assertOk();
    }

    public function test_group_dates_and_course_are_guarded_on_partial_update(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->patchJson("/v1/groups/{$this->own->id}", ['end_date' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->patchJson("/v1/groups/{$this->own->id}", ['end_date' => '2026-12-01'])->assertOk();
        $this->patchJson("/v1/groups/{$this->own->id}", ['start_date' => '2027-01-01'])->assertUnprocessable();

        $other = Course::create(['title' => 'K2', 'created_by' => $this->teacher->id]);
        $this->patchJson("/v1/groups/{$this->own->id}", ['course_id' => $other->id])->assertOk(); // hali bo'sh guruh
        DB::table('group_members')->insert(['group_id' => $this->own->id, 'student_id' => User::factory()->create()->id, 'joined_at' => now()]);
        $this->patchJson("/v1/groups/{$this->own->id}", ['course_id' => $this->course->id])->assertUnprocessable()->assertJsonValidationErrors('course_id');
    }

    public function test_attempted_quiz_questions_keep_option_ids(): void
    {
        $student = User::factory()->create();
        DB::table('group_members')->insert(['group_id' => $this->own->id, 'student_id' => $student->id, 'joined_at' => now()]);
        Sanctum::actingAs($this->teacher);
        $quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'T', 'published' => true])->json('quiz.id');
        $q = $this->postJson("/v1/quizzes/$quizId/questions", ['type' => 'SINGLE', 'text' => '?', 'options' => [
            ['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]])->json('question');

        Sanctum::actingAs($student);
        $attempt = $this->postJson("/v1/quizzes/$quizId/attempts")->assertSuccessful()->json('attempt.id');
        // Bo'sh javob 422 bermaydi.
        $this->postJson("/v1/attempts/$attempt/submit", ['answers' => [(string) $q['id'] => ['']]])->assertOk();

        Sanctum::actingAs($this->teacher);
        $ids = collect($q['options'])->pluck('id')->all();
        $this->patchJson("/v1/questions/{$q['id']}", ['options' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => false]]])->assertOk()
            ->assertJsonPath('question.options.0.text', 'A');
        $this->assertSame($ids, DB::table('question_options')->where('question_id', $q['id'])->orderBy('id')->pluck('id')->all());
        $this->patchJson("/v1/questions/{$q['id']}", ['options' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => false], ['text' => 'C', 'is_correct' => false]]])->assertStatus(409);
        $this->deleteJson("/v1/questions/{$q['id']}")->assertStatus(409);
    }

    public function test_string_setting_can_be_cleared(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());
        $this->patchJson('/v1/settings', ['values' => ['site.logo_url' => '']])->assertOk();
    }

    public function test_csv_cells_are_formula_safe(): void
    {
        $evil = User::factory()->create(['first_name' => '=HYPERLINK("http://x")']);
        DB::table('group_members')->insert(['group_id' => $this->own->id, 'student_id' => $evil->id, 'joined_at' => now()->subDay()]);
        $sid = DB::table('class_sessions')->insertGetId(['group_id' => $this->own->id, 'starts_at' => now()->subHour(), 'ends_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $evil->id, 'status' => 'PRESENT', 'marked_by' => $this->teacher->id, 'marked_at' => now()]);
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $csv = $this->get('/v1/reports/attendance.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_can_edit_flag_and_teacher_lookup(): void
    {
        $other = User::factory()->role(Role::Teacher)->create();
        Sanctum::actingAs($other);
        // Kursga aloqasi bo'lmagan o'qituvchi: ko'radi, lekin tahrirlay olmaydi.
        $this->getJson("/v1/courses/{$this->course->id}")->assertOk()->assertJsonPath('can_edit', false);
        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/courses/{$this->course->id}")->assertOk()->assertJsonPath('can_edit', true);
        $this->getJson('/v1/groups/teachers')->assertForbidden(); // o'qituvchida group.manage yo'q

        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $ids = collect($this->getJson('/v1/groups/teachers')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($this->teacher->id));
        $this->assertFalse($ids->contains(User::factory()->create()->id));
    }

    public function test_single_branch_groups_and_no_branch_api(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $id = $this->postJson('/v1/groups', ['name' => 'Yangi', 'course_id' => $this->course->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01'])
            ->assertCreated()->json('group.id');
        $this->assertSame(\App\Models\Branch::primaryId(), (int) StudyGroup::find($id)->branch_id);
        $this->assertSame('TISU', \App\Models\Branch::find(\App\Models\Branch::primaryId())->name);
        $this->getJson('/v1/branches')->assertNotFound();
    }
}
