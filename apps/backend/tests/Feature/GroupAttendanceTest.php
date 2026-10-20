<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $parent;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 16:00:00'); // chorshanba
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create(['first_name' => 'Aziz', 'last_name' => 'K']);
        $this->parent = User::factory()->role(Role::Parent)->create();
        DB::table('telegram_links')->insert(['user_id' => $this->parent->id, 'telegram_id' => 77, 'chat_id' => 77, 'linked_at' => now()]);
        $branch = Branch::create(['name' => 'Markaz']);
        $course = Course::create(['title' => 'Ingliz', 'created_by' => $this->admin->id]);

        Sanctum::actingAs($this->admin);
        $this->groupId = $this->postJson('/v1/groups', [
            'name' => 'ENG-1', 'course_id' => $course->id, 'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id,
            'start_date' => '2026-10-19',
            'schedules' => [['weekday' => 1, 'start_time' => '14:00', 'end_time' => '15:30'], ['weekday' => 3, 'start_time' => '14:00', 'end_time' => '15:30']],
        ])->assertCreated()->json('group.id');
        $this->postJson("/v1/groups/{$this->groupId}/members", ['student_id' => $this->student->id])->assertCreated();
        $this->postJson("/v1/users/{$this->parent->id}/children", ['child_id' => $this->student->id])->assertCreated();
    }

    private function sessionAt(string $startsAt): int
    {
        return (int) DB::table('class_sessions')->where('group_id', $this->groupId)->where('starts_at', $startsAt)->value('id');
    }

    public function test_generate_sessions_is_idempotent(): void
    {
        $r = $this->postJson("/v1/groups/{$this->groupId}/sessions/generate", ['from' => '2026-10-19', 'to' => '2026-11-01'])->assertOk();
        $this->assertSame(4, $r->json('created'));
        $this->postJson("/v1/groups/{$this->groupId}/sessions/generate", ['from' => '2026-10-19', 'to' => '2026-11-01'])
            ->assertJsonPath('created', 0);
    }

    public function test_teacher_marks_attendance_with_auto_status_and_parent_is_notified(): void
    {
        $this->postJson("/v1/groups/{$this->groupId}/sessions/generate", ['from' => '2026-10-19', 'to' => '2026-10-25']);
        $today = $this->sessionAt('2026-10-21 14:00:00');

        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/sessions/$today/attendance")->assertOk()->assertJsonPath('rows.0.status', null);
        // 14:12 da keldi → sozlama (5/30) bo'yicha LATE.
        $this->putJson("/v1/sessions/$today/attendance", ['items' => [['student_id' => $this->student->id, 'arrived_at' => '14:12']]])
            ->assertOk()->assertJsonPath('notified', 0);
        $this->getJson("/v1/sessions/$today/attendance")->assertJsonPath('rows.0.status', 'LATE');

        // Sozlama o'zgarsa qoida ham o'zgaradi.
        app(Settings::class)->set(['attendance.grace_minutes' => 15], null);
        $this->putJson("/v1/sessions/$today/attendance", ['items' => [['student_id' => $this->student->id, 'arrived_at' => '14:12']]]);
        $this->getJson("/v1/sessions/$today/attendance")->assertJsonPath('rows.0.status', 'PRESENT');

        $this->putJson("/v1/sessions/$today/attendance", ['items' => [['student_id' => $this->student->id, 'status' => 'ABSENT']]])
            ->assertJsonPath('notified', 1);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'Aziz K') && str_contains($r['text'], 'kelmadi'));
        // Holat o'zgarmasa qayta xabar yuborilmaydi.
        $this->putJson("/v1/sessions/$today/attendance", ['items' => [['student_id' => $this->student->id, 'status' => 'ABSENT']]])
            ->assertJsonPath('notified', 0);

        $this->getJson("/v1/groups/{$this->groupId}/attendance")->assertOk()->assertJsonPath('data.0.rate', fn ($v) => (float) $v === 0.0);
    }

    public function test_marking_rules(): void
    {
        $this->postJson("/v1/groups/{$this->groupId}/sessions/generate", ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $future = $this->sessionAt('2026-10-26 14:00:00');
        $old = $this->sessionAt('2026-10-19 14:00:00');
        $item = ['items' => [['student_id' => $this->student->id, 'status' => 'PRESENT']]];

        Sanctum::actingAs($this->teacher);
        $this->putJson("/v1/sessions/$future/attendance", $item)->assertUnprocessable();
        app(Settings::class)->set(['attendance.teacher_edit_days' => 1], null);
        $this->putJson("/v1/sessions/$old/attendance", $item)->assertForbidden();
        $this->putJson("/v1/sessions/$old/attendance", ['items' => [['student_id' => 999999, 'status' => 'PRESENT']]])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson("/v1/sessions/$old/attendance", $item)->assertOk();

        $other = User::factory()->role(Role::Teacher)->create();
        Sanctum::actingAs($other);
        $this->getJson("/v1/groups/{$this->groupId}")->assertNotFound();
        $this->putJson("/v1/sessions/$old/attendance", $item)->assertNotFound();
    }

    public function test_student_and_parent_see_own_group_only(): void
    {
        Sanctum::actingAs($this->student);
        $this->getJson('/v1/groups')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/v1/groups', [])->assertForbidden();

        Sanctum::actingAs($this->parent);
        $this->getJson('/v1/groups')->assertJsonCount(1, 'data');
        $this->getJson("/v1/groups/{$this->groupId}/attendance")->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/v1/groups')->assertJsonCount(0, 'data');
    }
}
