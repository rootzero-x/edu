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

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $other;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 16:00:00'); // chorshanba
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create();
        $this->other = User::factory()->create();
        $branch = Branch::create(['name' => 'Markaz']);
        $course = Course::create(['title' => 'Ingliz', 'created_by' => $this->admin->id, 'published' => true]);
        Course::create(['title' => 'Yashirin', 'created_by' => $this->admin->id]);

        Sanctum::actingAs($this->admin);
        $this->groupId = $this->postJson('/v1/groups', [
            'name' => 'ENG-1', 'course_id' => $course->id, 'branch_id' => $branch->id, 'teacher_id' => $this->teacher->id,
            'start_date' => '2026-10-19', 'room' => '101',
            'schedules' => [['weekday' => 1, 'start_time' => '14:00', 'end_time' => '15:30'], ['weekday' => 3, 'start_time' => '14:00', 'end_time' => '15:30']],
        ])->assertCreated()->json('group.id');
        $this->postJson("/v1/groups/{$this->groupId}/members", ['student_id' => $this->student->id])->assertCreated();
        $this->postJson("/v1/groups/{$this->groupId}/members", ['student_id' => $this->other->id])->assertCreated();
        $this->postJson("/v1/groups/{$this->groupId}/sessions/generate", ['from' => '2026-10-19', 'to' => '2026-10-25'])->assertOk();
    }

    private function sessionAt(string $startsAt): int
    {
        return (int) DB::table('class_sessions')->where('group_id', $this->groupId)->where('starts_at', $startsAt)->value('id');
    }

    public function test_requires_login(): void
    {
        $this->getJson('/v1/dashboard/summary')->assertUnauthorized();
    }

    public function test_teacher_sees_today_sessions_and_marking_progress(): void
    {
        Sanctum::actingAs($this->teacher);
        $r = $this->getJson('/v1/dashboard/summary')->assertOk()
            ->assertJsonPath('role', 'TEACHER')
            ->assertJsonCount(1, 'today')
            ->assertJsonPath('today.0.group_name', 'ENG-1')
            ->assertJsonPath('today.0.course_title', 'Ingliz')
            ->assertJsonPath('today.0.room', '101')
            ->assertJsonPath('today.0.students_count', 2)
            ->assertJsonPath('today.0.marked_count', 0)
            ->assertJsonPath('today.0.can_mark', true)
            ->assertJsonPath('stats.today_sessions', 1)
            ->assertJsonPath('stats.today_marked', 0)
            ->assertJsonPath('stats.attendance_rate', null)
            ->assertJsonPath('stats.groups', 1)
            ->assertJsonPath('stats.students', 2);
        // Dushanba va bugungi (chorshanba) darslar belgilanmagan.
        $this->assertSame(2, $r->json('stats.unmarked_sessions'));

        $today = $this->sessionAt('2026-10-21 14:00:00');
        $this->putJson("/v1/sessions/$today/attendance", ['items' => [
            ['student_id' => $this->student->id, 'status' => 'PRESENT'],
            ['student_id' => $this->other->id, 'status' => 'ABSENT'],
        ]])->assertOk();

        $r = $this->getJson('/v1/dashboard/summary')->assertOk()
            ->assertJsonPath('today.0.marked_count', 2)
            ->assertJsonPath('stats.today_marked', 1)
            ->assertJsonPath('stats.attendance_counts.PRESENT', 1)
            ->assertJsonPath('stats.attendance_counts.ABSENT', 1);
        $this->assertEquals(50.0, $r->json('stats.attendance_rate'));
        $this->assertSame(1, $r->json('stats.unmarked_sessions'));
    }

    public function test_student_sees_only_own_attendance_and_published_courses(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->putJson('/v1/sessions/'.$this->sessionAt('2026-10-21 14:00:00').'/attendance', ['items' => [
            ['student_id' => $this->student->id, 'status' => 'PRESENT'],
            ['student_id' => $this->other->id, 'status' => 'ABSENT'],
        ]])->assertOk();

        Sanctum::actingAs($this->student);
        $r = $this->getJson('/v1/dashboard/summary')->assertOk()
            ->assertJsonCount(1, 'today')
            ->assertJsonPath('today.0.can_mark', false)
            ->assertJsonPath('stats.courses', 1)
            ->assertJsonPath('stats.students', null)
            ->assertJsonPath('stats.unmarked_sessions', null)
            ->assertJsonPath('stats.attendance_counts.ABSENT', 0);
        $this->assertEquals(100.0, $r->json('stats.attendance_rate'));
    }

    public function test_admin_counts_and_outsider_sees_nothing(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/v1/dashboard/summary')->assertOk()
            ->assertJsonPath('stats.courses', 2)
            ->assertJsonPath('stats.students', 2)
            ->assertJsonPath('today.0.can_mark', true);

        $outsider = User::factory()->role(Role::Teacher)->create();
        Sanctum::actingAs($outsider);
        $this->getJson('/v1/dashboard/summary')->assertOk()
            ->assertJsonCount(0, 'today')
            ->assertJsonPath('stats.groups', 0)
            ->assertJsonPath('stats.unmarked_sessions', 0);
    }

    public function test_attendance_period_comes_from_settings(): void
    {
        Sanctum::actingAs($this->admin);
        $this->putJson('/v1/sessions/'.$this->sessionAt('2026-10-19 14:00:00').'/attendance', ['items' => [
            ['student_id' => $this->student->id, 'status' => 'ABSENT'],
        ]])->assertOk();
        $this->getJson('/v1/dashboard/summary')->assertJsonPath('stats.attendance_days', 30)
            ->assertJsonPath('stats.attendance_counts.ABSENT', 1);

        app(Settings::class)->set(['ui.summary_attendance_days' => 1], null);
        $this->getJson('/v1/dashboard/summary')->assertJsonPath('stats.attendance_days', 1)
            ->assertJsonPath('stats.attendance_counts.ABSENT', 0)
            ->assertJsonPath('stats.attendance_rate', null);
    }

    public function test_public_courses_list_published_only_and_respects_limit(): void
    {
        $this->getJson('/v1/public/courses')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Ingliz')
            ->assertJsonPath('data.0.modules_count', 0);

        app(Settings::class)->set(['site.courses_limit' => 0], null);
        $this->getJson('/v1/public/courses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_new_ui_settings_are_public(): void
    {
        $s = $this->getJson('/v1/public/settings')->assertOk()->json('settings');
        $this->assertSame(20, $s['ui.refresh_seconds']);
        $this->assertArrayHasKey('site.hero_title', $s);
        $this->assertArrayHasKey('login.telegram_button', $s);
        $this->assertArrayNotHasKey('ui.summary_attendance_days', $s);
    }
}
