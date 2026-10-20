<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Course;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sprint 3 #16 (audit jurnali) va #23 (sozlamalar xavfsizligi: tarix, qulflanishdan himoya, faqat-admin huquq). */
class AuditSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::factory()->role(Role::SuperAdmin)->create();
        $this->admin = User::factory()->role(Role::Admin)->create(['first_name' => 'Dilnoza']);
        $this->teacher = User::factory()->role(Role::Teacher)->create();
    }

    public function test_audit_list_with_diff_and_filters_is_admin_only(): void
    {
        Sanctum::actingAs($this->admin);
        $course = Course::create(['title' => 'Ingliz', 'created_by' => $this->admin->id]);
        $gid = $this->postJson('/v1/groups', ['name' => 'ENG-1', 'course_id' => $course->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01'])
            ->assertCreated()->json('group.id');
        $this->patchJson("/v1/groups/$gid", ['name' => 'ENG-2', 'end_date' => '2027-01-31'])->assertOk();

        $r = $this->getJson('/v1/audit?action=group.update')->assertOk()->assertJsonCount(1, 'data');
        $row = $r->json('data.0');
        $this->assertSame('Dilnoza', explode(' ', $row['actor']['name'])[0]);
        $this->assertSame("Guruh o'zgartirildi", $row['action_label']);
        $this->assertSame('ENG-2', $row['entity_name']);
        $this->assertContains(['field' => 'name', 'from' => 'ENG-1', 'to' => 'ENG-2'], $row['diff']);
        $this->assertContains(['field' => 'end_date', 'from' => null, 'to' => '2027-01-31'], $row['diff']);

        $this->getJson('/v1/audit?action=group')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/v1/audit?entity=group&entity_id=$gid")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/v1/audit?actor_id={$this->teacher->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/v1/audit?from=2020-01-01&to=2026-12-31')->assertUnprocessable();
        $f = $this->getJson('/v1/audit/facets')->assertOk();
        $this->assertContains('group.update', $f->json('actions'));
        $this->assertSame($this->admin->id, $f->json('actors.0.id'));

        Sanctum::actingAs($this->teacher);
        $this->getJson('/v1/audit')->assertForbidden();
    }

    public function test_attendance_changes_show_student_names(): void
    {
        $student = User::factory()->create(['first_name' => 'Aziz', 'last_name' => 'K']);
        DB::table('audit_logs')->insert(['actor_id' => $this->teacher->id, 'action' => 'attendance.mark', 'entity' => 'session', 'entity_id' => 1,
            'data' => json_encode(['changes' => [['student_id' => $student->id, 'from' => 'ABSENT', 'to' => 'PRESENT']], 'reason' => 'Xato']), 'created_at' => now()]);
        Sanctum::actingAs($this->super);
        $row = $this->getJson('/v1/audit')->assertOk()->json('data.0');
        $this->assertSame([['field' => 'Aziz K', 'from' => 'ABSENT', 'to' => 'PRESENT']], $row['diff']);
        $this->assertSame('Xato', $row['note']);
    }

    public function test_settings_changes_record_old_and_new_values_and_history(): void
    {
        Sanctum::actingAs($this->super);
        $this->patchJson('/v1/settings', ['values' => ['site.name' => 'TISU Pro']])->assertOk();
        $this->patchJson('/v1/settings', ['values' => ['site.name' => 'TISU Max', 'ui.page_size' => 40]])->assertOk();
        $this->deleteJson('/v1/settings/site.name')->assertOk();

        $h = $this->getJson('/v1/settings/site.name/history')->assertOk()->json('data');
        $this->assertCount(3, $h);
        $this->assertSame(['from' => 'TISU Max', 'to' => 'TISU Edu', 'reset' => true], array_intersect_key($h[0], array_flip(['from', 'to', 'reset'])));
        $this->assertSame(['TISU Pro', 'TISU Max'], [$h[1]['from'], $h[1]['to']]);
        $this->assertSame(['TISU Edu', 'TISU Pro'], [$h[2]['from'], $h[2]['to']]);
        $this->getJson('/v1/settings/no.such.key/history')->assertNotFound();

        $row = $this->getJson('/v1/audit?action=settings.update')->json('data.0');
        $this->assertContains(['field' => 'ui.page_size', 'from' => 50, 'to' => 40], $row['diff']);

        // Ro'yxat sozlamalari indeksi: inson o'qiydigan huquq nomlari va kalitlarni ko'rsatish belgisi.
        $idx = $this->getJson('/v1/settings')->assertOk();
        $this->assertTrue($idx->json('show_keys'));
        $this->assertSame("Davomat belgilash", $idx->json('permission_labels')['attendance.mark']);
    }

    public function test_audit_view_is_admin_only_permission(): void
    {
        Sanctum::actingAs($this->super);
        $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => ['TEACHER' => ['course.view', 'audit.view']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('roles.permissions');
        $this->assertContains('audit.view', $this->admin->fresh()->toApi()['permissions']);
    }

    public function test_lockout_guard_on_role_matrix(): void
    {
        $matrix = app(Settings::class)->get('roles.permissions');
        $matrix['ADMIN'][] = 'settings.manage';
        app(Settings::class)->set(['roles.permissions' => $matrix], null);

        // Admin o'z rolidan settings.manage ni olib tashlay olmaydi.
        Sanctum::actingAs($this->admin);
        $without = array_values(array_diff($matrix['ADMIN'], ['settings.manage']));
        $r = $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => ['ADMIN' => $without]]])->assertUnprocessable();
        $this->assertStringContainsString('settings.manage', $r->json('errors')['roles.permissions'][0]);
        $this->deleteJson('/v1/settings/roles.permissions')->assertUnprocessable();
        // Boshqa o'zgarishlar — mumkin.
        $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => ['TEACHER' => ['course.view', 'attendance.mark']]]])->assertOk();

        // Super-admin — mumkin (u har doim hamma huquqqa ega).
        Sanctum::actingAs($this->super);
        $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => ['ADMIN' => $without]]])->assertOk();

        // Faol super-admin bo'lmasa — user.manage oxirgi roldan olib tashlanmaydi.
        $this->super->update(['active' => false]);
        $other = User::factory()->role(Role::SuperAdmin)->create(['active' => false]);
        $matrix = app(Settings::class)->get('roles.permissions');
        $matrix['ADMIN'][] = 'settings.manage';
        app(Settings::class)->set(['roles.permissions' => $matrix], null);
        Sanctum::actingAs($this->admin);
        $noUsers = array_values(array_diff($matrix['ADMIN'], ['user.manage']));
        $r = $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => ['ADMIN' => $noUsers]]])->assertUnprocessable();
        $this->assertStringContainsString('user.manage', $r->json('errors')['roles.permissions'][0]);
        $this->assertFalse($other->fresh()->active);
    }

    public function test_question_crud_is_audited(): void
    {
        Sanctum::actingAs($this->admin);
        $course = Course::create(['title' => 'Ingliz', 'created_by' => $this->admin->id]);
        $quiz = $this->postJson("/v1/courses/{$course->id}/quizzes", ['title' => 'Test 1'])->assertCreated()->json('quiz.id');
        $q = $this->postJson("/v1/quizzes/$quiz/questions", ['type' => 'SINGLE', 'text' => '2+2?', 'options' => [
            ['text' => '4', 'is_correct' => true], ['text' => '5', 'is_correct' => false],
        ]])->assertCreated()->json('question.id');
        $this->patchJson("/v1/questions/$q", ['text' => '2+2 = ?'])->assertOk();
        $this->deleteJson("/v1/questions/$q")->assertOk();
        $this->assertSame(['question.create', 'question.update', 'question.delete'],
            DB::table('audit_logs')->where('action', 'like', 'question.%')->orderBy('id')->pluck('action')->all());
        $row = $this->getJson('/v1/audit?action=question.update')->json('data.0');
        $this->assertContains(['field' => 'text', 'from' => '2+2?', 'to' => '2+2 = ?'], $row['diff']);
    }
}
