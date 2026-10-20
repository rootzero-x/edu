<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\GroupSchedule;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\SessionGenerator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceEditTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    private User $parent;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 16:00:00');
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create(['first_name' => 'Ali']);
        $this->parent = User::factory()->role(Role::Parent)->create();
        DB::table('parent_child')->insert(['parent_id' => $this->parent->id, 'child_id' => $this->student->id]);
        DB::table('telegram_links')->insert(['user_id' => $this->parent->id, 'telegram_id' => 9, 'chat_id' => 9, 'linked_at' => now()]);
        $this->group = StudyGroup::create(['name' => 'G1', 'course_id' => Course::create(['title' => 'K', 'created_by' => $this->teacher->id])->id,
            'branch_id' => Branch::create(['name' => 'B'])->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-09-01']);
        GroupSchedule::create(['group_id' => $this->group->id, 'weekday' => 3, 'start_time' => '14:00', 'end_time' => '15:00']);
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $this->student->id, 'joined_at' => now()]);
        app(SessionGenerator::class)->generate($this->group->load('schedules'), '2026-09-01', '2026-10-28');
    }

    private function sid(string $date): int
    {
        return (int) DB::table('class_sessions')->where('starts_at', "$date 14:00:00")->value('id');
    }

    public function test_unmarked_list_and_fixing_old_session_with_history(): void
    {
        Sanctum::actingAs($this->teacher);
        $r = $this->getJson('/v1/attendance/unmarked')->assertOk();
        $this->assertSame(8, $r->json('total')); // 2.09 … 21.10 chorshanbalar (lookback 60 kun)

        $old = $this->sid('2026-10-07');
        $this->getJson("/v1/sessions/$old/attendance")->assertJsonPath('edit.can_edit', true);
        $this->putJson("/v1/sessions/$old/attendance", ['items' => [['student_id' => $this->student->id, 'status' => 'ABSENT']]])
            ->assertOk()->assertJsonPath('notified', 0); // eski darsni tuzatish — ota-onaga xabar yo'q
        $this->putJson("/v1/sessions/$old/attendance", ['items' => [['student_id' => $this->student->id, 'status' => 'EXCUSED']], 'reason' => "Ma'lumotnoma keltirdi"])
            ->assertOk();

        $h = $this->getJson("/v1/sessions/$old/attendance/history")->assertOk()->json('data');
        $this->assertCount(2, $h);
        $this->assertSame(['ABSENT', 'EXCUSED'], [$h[0]['changes'][0]['from'], $h[0]['changes'][0]['to']]);
        $this->assertSame("Ma'lumotnoma keltirdi", $h[0]['reason']);
        $this->assertSame('Ali '.$this->student->last_name, $h[0]['changes'][0]['name']);
        $this->assertSame(7, $this->getJson('/v1/attendance/unmarked')->json('total'));
    }

    public function test_edit_window_and_reason_follow_settings(): void
    {
        $old = $this->sid('2026-09-09');
        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/sessions/$old/attendance")->assertJsonPath('edit.can_edit', false);
        $this->putJson("/v1/sessions/$old/attendance", ['items' => [['student_id' => $this->student->id, 'status' => 'PRESENT']]])->assertForbidden();

        app(Settings::class)->set(['attendance.teacher_edit_days' => 0, 'attendance.edit_reason_required_after_days' => 7], null);
        $this->getJson("/v1/sessions/$old/attendance")->assertJsonPath('edit.can_edit', true)->assertJsonPath('edit.reason_required', true);
        $this->putJson("/v1/sessions/$old/attendance", ['items' => [['student_id' => $this->student->id, 'status' => 'PRESENT']]])->assertUnprocessable();
        $this->putJson("/v1/sessions/$old/attendance", ['items' => [['student_id' => $this->student->id, 'status' => 'PRESENT']], 'reason' => 'Tuzatish'])->assertOk();
    }

    public function test_same_day_marking_still_notifies_parent(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->putJson('/v1/sessions/'.$this->sid('2026-10-21').'/attendance', ['items' => [['student_id' => $this->student->id, 'status' => 'ABSENT']]])
            ->assertJsonPath('notified', 1);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), '21.10.2026 kuni'));
    }

    public function test_student_cannot_see_unmarked_or_history(): void
    {
        Sanctum::actingAs($this->student);
        $this->getJson('/v1/attendance/unmarked')->assertForbidden();
        $this->getJson('/v1/sessions/'.$this->sid('2026-10-21').'/attendance/history')->assertForbidden();
    }
}
