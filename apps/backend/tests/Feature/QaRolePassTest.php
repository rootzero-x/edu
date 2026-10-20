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

/** Rollar bo'yicha QA hisoboti (docs/qa/QA-REPORT.md) topilmalari. */
class QaRolePassTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private Course $course;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create(['phone' => '+998900000010']);
        $this->course = Course::create(['title' => 'K', 'created_by' => $this->teacher->id, 'published' => true]);
        $this->group = StudyGroup::create(['name' => 'G1', 'course_id' => $this->course->id, 'branch_id' => Branch::create(['name' => 'B'])->id,
            'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01', 'active' => true]);
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $this->student->id, 'joined_at' => now()->subDays(5), 'discount_id' => null]);
    }

    /** #3 */
    public function test_group_teacher_cannot_be_demoted_and_role_change_revokes_tokens(): void
    {
        $this->teacher->createToken('t');
        Sanctum::actingAs($this->admin);
        $this->patchJson("/v1/users/{$this->teacher->id}", ['role' => 'STUDENT'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame(Role::Teacher, $this->teacher->fresh()->role);
        $this->assertSame(1, $this->teacher->tokens()->count());

        // Guruhi faol bo'lmasa — mumkin, eski tokenlar bekor qilinadi.
        $this->group->update(['active' => false]);
        $this->patchJson("/v1/users/{$this->teacher->id}", ['role' => 'STUDENT'])->assertOk()->assertJsonPath('user.role', 'STUDENT');
        $this->assertSame(0, $this->teacher->tokens()->count());

        // Ota-onaga faqat o'quvchi farzand qilib bog'lanadi.
        $parent = User::factory()->role(Role::Parent)->create();
        $other = User::factory()->role(Role::Teacher)->create();
        $this->postJson("/v1/users/{$parent->id}/children", ['child_id' => $other->id])->assertUnprocessable();
        $this->postJson("/v1/users/{$parent->id}/children", ['child_id' => $this->student->id])->assertCreated();
    }

    /** #8 */
    public function test_permission_is_checked_before_model_binding(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson('/v1/users/999999/children')->assertForbidden();
        $this->getJson("/v1/users/{$this->student->id}/children")->assertForbidden();
        $this->patchJson('/v1/users/999999', ['first_name' => 'X'])->assertForbidden();
    }

    /** #4 */
    public function test_teacher_sees_certificates_of_own_groups_only(): void
    {
        $foreignTeacher = User::factory()->role(Role::Teacher)->create();
        $foreign = StudyGroup::create(['name' => 'G2', 'course_id' => $this->course->id, 'branch_id' => $this->group->branch_id,
            'teacher_id' => $foreignTeacher->id, 'start_date' => '2026-10-01']);
        $s2 = User::factory()->create();
        DB::table('certificates')->insert([
            ['code' => 'OWN-1', 'student_id' => $this->student->id, 'course_id' => $this->course->id, 'group_id' => $this->group->id, 'issued_at' => now()],
            ['code' => 'FOREIGN-1', 'student_id' => $s2->id, 'course_id' => $this->course->id, 'group_id' => $foreign->id, 'issued_at' => now()],
        ]);

        Sanctum::actingAs($this->teacher);
        $this->getJson('/v1/certificates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'OWN-1');
        // Berish/bekor qilish — faqat group.manage.
        $this->postJson("/v1/groups/{$this->group->id}/certificates")->assertForbidden();
        $this->deleteJson('/v1/certificates/OWN-1')->assertForbidden();
    }

    /** #9 */
    public function test_classmates_pivot_is_hidden_from_non_staff(): void
    {
        Sanctum::actingAs($this->student);
        $this->getJson("/v1/groups/{$this->group->id}")->assertOk()->assertJsonMissingPath('group.students.0.pivot')
            ->assertJsonMissingPath('group.students.0.phone');

        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/groups/{$this->group->id}")->assertOk()->assertJsonPath('group.students.0.pivot.discount_id', null)
            ->assertJsonPath('group.students.0.phone', '+998900000010');
    }

    /** #5 */
    public function test_csv_keeps_phone_as_text_and_uses_locale_decimal(): void
    {
        $this->markSessions(['PRESENT', 'PRESENT', 'ABSENT']); // 66.7%
        Sanctum::actingAs($this->admin);
        $csv = $this->get('/v1/reports/attendance.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('"=""+998900000010"""', $csv);
        $this->assertStringContainsString(';66,7', $csv);

        app(Settings::class)->set(['site.default_locale' => 'en'], null);
        $this->assertStringContainsString(';66.7', $this->get('/v1/reports/attendance.csv')->streamedContent());
    }

    /** #2 */
    public function test_partially_marked_session_counts_as_unmarked(): void
    {
        Carbon::setTestNow('2026-10-21 18:00:00');
        $s2 = User::factory()->create();
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $s2->id, 'joined_at' => now()->subDays(5)]);
        $sid = DB::table('class_sessions')->insertGetId(['group_id' => $this->group->id, 'starts_at' => '2026-10-21 14:00:00',
            'ends_at' => '2026-10-21 15:00:00', 'cancelled' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $this->student->id, 'status' => 'PRESENT', 'marked_by' => $this->teacher->id, 'marked_at' => now()]);

        Sanctum::actingAs($this->teacher);
        $this->getJson('/v1/dashboard/summary')->assertOk()->assertJsonPath('stats.today_sessions', 1)
            ->assertJsonPath('stats.today_marked', 0)->assertJsonPath('stats.unmarked_sessions', 1);
        $this->getJson('/v1/reports/attendance?from=2026-10-21&to=2026-10-21')->assertJsonPath('total.unmarked_sessions', 1);

        DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $s2->id, 'status' => 'ABSENT', 'marked_by' => $this->teacher->id, 'marked_at' => now()]);
        $this->getJson('/v1/dashboard/summary')->assertJsonPath('stats.today_marked', 1)->assertJsonPath('stats.unmarked_sessions', 0);
        $this->getJson('/v1/reports/attendance?from=2026-10-21&to=2026-10-21')->assertJsonPath('total.unmarked_sessions', 0);
    }

    /** #7 */
    public function test_correct_option_cannot_change_after_attempts(): void
    {
        Sanctum::actingAs($this->teacher);
        $quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'T', 'published' => true])->json('quiz.id');
        $q = $this->postJson("/v1/quizzes/$quizId/questions", ['type' => 'SINGLE', 'text' => '?', 'options' => [
            ['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]])->json('question');
        Sanctum::actingAs($this->student);
        $this->postJson("/v1/quizzes/$quizId/attempts")->assertOk();

        Sanctum::actingAs($this->teacher);
        $this->patchJson("/v1/questions/{$q['id']}", ['options' => [['text' => 'a', 'is_correct' => false], ['text' => 'b', 'is_correct' => true]]])
            ->assertStatus(409);
        $this->assertTrue((bool) DB::table('question_options')->where('question_id', $q['id'])->where('text', 'a')->value('is_correct'));
        $this->patchJson("/v1/questions/{$q['id']}", ['text' => 'Savol?', 'options' => [['text' => 'A', 'is_correct' => true], ['text' => 'B', 'is_correct' => false]]])
            ->assertOk()->assertJsonPath('question.options.1.text', 'B');
    }

    /** #7 (davomi): ball va matnli javob kaliti ham urinishdan keyin qulflanadi. */
    public function test_points_and_accepted_answers_locked_after_attempts(): void
    {
        Sanctum::actingAs($this->teacher);
        $quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'T', 'published' => true])->json('quiz.id');
        $q = $this->postJson("/v1/quizzes/$quizId/questions", ['type' => 'TEXT', 'text' => 'Poytaxt?', 'points' => 2,
            'accepted_answers' => ['Toshkent']])->json('question');
        Sanctum::actingAs($this->student);
        $this->postJson("/v1/quizzes/$quizId/attempts")->assertOk();

        Sanctum::actingAs($this->teacher);
        $this->patchJson("/v1/questions/{$q['id']}", ['accepted_answers' => ['Toshkent', 'Tashkent']])->assertStatus(409);
        $this->patchJson("/v1/questions/{$q['id']}", ['points' => 5])->assertStatus(409);
        // Bir xil qiymatlar (katta-kichik harf/bo'shliq farqi) va matn tahriri — mumkin.
        $this->patchJson("/v1/questions/{$q['id']}", ['text' => "O'zbekiston poytaxti?", 'points' => 2, 'accepted_answers' => [' toshkent ']])
            ->assertOk()->assertJsonPath('question.text', "O'zbekiston poytaxti?");
    }

    /** #3 (davomi): guruhdagi yoki farzand sifatida bog'langan o'quvchining roli o'zgarmaydi. */
    public function test_enrolled_or_linked_student_role_cannot_change(): void
    {
        Sanctum::actingAs($this->admin);
        $this->patchJson("/v1/users/{$this->student->id}", ['role' => 'TEACHER'])->assertUnprocessable()->assertJsonValidationErrors('role');

        DB::table('group_members')->where('student_id', $this->student->id)->update(['left_at' => now()]);
        $parent = User::factory()->role(Role::Parent)->create();
        DB::table('parent_child')->insert(['parent_id' => $parent->id, 'child_id' => $this->student->id]);
        $this->patchJson("/v1/users/{$this->student->id}", ['role' => 'TEACHER'])->assertUnprocessable();

        DB::table('parent_child')->delete();
        $this->patchJson("/v1/users/{$this->student->id}", ['role' => 'TEACHER'])->assertOk()->assertJsonPath('user.role', 'TEACHER');
    }

    /** #10: ota-ona test topshira olmaydi — boshlash imkoni ko'rsatilmaydi. */
    public function test_parent_cannot_start_quiz(): void
    {
        Sanctum::actingAs($this->teacher);
        $quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'T', 'published' => true])->json('quiz.id');
        $parent = User::factory()->role(Role::Parent)->create();
        DB::table('parent_child')->insert(['parent_id' => $parent->id, 'child_id' => $this->student->id]);

        Sanctum::actingAs($parent);
        $this->getJson("/v1/quizzes/$quizId")->assertOk()->assertJsonPath('can_take', false)->assertJsonPath('can_start', false);
        Sanctum::actingAs($this->student);
        $this->getJson("/v1/quizzes/$quizId")->assertOk()->assertJsonPath('can_start', true);
    }

    /** #11 */
    public function test_image_urls_must_be_https(): void
    {
        Sanctum::actingAs($this->teacher);
        foreach (['ftp://x.com/a.png', 'http://x.com/a.png', 'javascript:alert(1)'] as $bad) {
            $this->patchJson("/v1/courses/{$this->course->id}", ['cover_url' => $bad])->assertUnprocessable();
        }
        $this->patchJson("/v1/courses/{$this->course->id}", ['cover_url' => 'https://x.com/a.png'])->assertOk();

        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());
        $this->patchJson('/v1/settings', ['values' => ['site.logo_url' => 'http://x.com/l.png']])->assertUnprocessable();
        $this->patchJson('/v1/settings', ['values' => ['site.hero_image_url' => 'ftp://x.com/h.png']])->assertUnprocessable();
        $this->patchJson('/v1/settings', ['values' => ['site.logo_url' => 'https://x.com/l.png']])->assertOk();
        // Rasm bo'lmagan havola sozlamalari o'zgarmaydi.
        $this->patchJson('/v1/settings', ['values' => ['site.map_url' => 'http://maps.example/x']])->assertOk();
    }

    /** #12 */
    public function test_quiz_report_excludes_students_who_left(): void
    {
        $left = User::factory()->create();
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $left->id, 'joined_at' => now()->subDays(5), 'left_at' => now()->subDay()]);
        $quizId = DB::table('quizzes')->insertGetId(['course_id' => $this->course->id, 'title' => 'Q', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$this->student->id => 80, $left->id => 20] as $sid => $pct) {
            DB::table('quiz_attempts')->insert(['quiz_id' => $quizId, 'student_id' => $sid, 'started_at' => now()->subHour(),
                'submitted_at' => now()->subMinutes(30), 'score' => $pct, 'max_score' => 100, 'percent' => $pct, 'passed' => $pct >= 60]);
        }

        Sanctum::actingAs($this->teacher);
        $this->getJson('/v1/reports/quizzes')->assertOk()->assertJsonPath('data.0.students', 1)->assertJsonPath('data.0.avg_percent', 80);
        $this->getJson('/v1/reports/quizzes?include_left=1')->assertJsonPath('data.0.students', 2)->assertJsonPath('data.0.avg_percent', 50);
    }

    /** #13 */
    public function test_empty_name_gives_single_clear_message(): void
    {
        app()->setLocale('uz');
        Sanctum::actingAs($this->student);
        $r = $this->patchJson('/v1/auth/me', ['first_name' => ''])->assertUnprocessable();
        $this->assertCount(1, $r->json('errors.first_name'));
        $this->assertSame("Ism kiritilishi shart.", $r->json('message'));

        Sanctum::actingAs($this->admin);
        $r = $this->patchJson("/v1/users/{$this->student->id}", ['first_name' => '   '])->assertUnprocessable();
        $this->assertCount(1, $r->json('errors.first_name'));
    }

    private function markSessions(array $statuses): void
    {
        foreach ($statuses as $i => $status) {
            $sid = DB::table('class_sessions')->insertGetId(['group_id' => $this->group->id, 'starts_at' => now()->subDays($i + 1),
                'ends_at' => now()->subDays($i + 1)->addHour(), 'cancelled' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $this->student->id, 'status' => $status,
                'marked_by' => $this->teacher->id, 'marked_at' => now()]);
        }
    }
}
