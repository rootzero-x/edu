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

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $otherTeacher;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 18:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->otherTeacher = User::factory()->role(Role::Teacher)->create();
        $branch = Branch::create(['name' => 'B']);
        $course = Course::create(['title' => 'K', 'created_by' => $this->admin->id]);
        $plan = [
            [$this->teacher, 'G1', ['PRESENT', 'ABSENT', 'ABSENT', 'ABSENT']],
            [$this->otherTeacher, 'G2', ['PRESENT', 'PRESENT', 'LATE', 'EXCUSED']],
        ];
        foreach ($plan as [$t, $name, $statuses]) {
            $g = StudyGroup::create(['name' => $name, 'course_id' => $course->id, 'branch_id' => $branch->id, 'teacher_id' => $t->id, 'start_date' => '2026-10-01']);
            $st = User::factory()->create(['first_name' => "S-$name"]);
            DB::table('group_members')->insert(['group_id' => $g->id, 'student_id' => $st->id, 'joined_at' => now()]);
            foreach ($statuses as $i => $status) {
                $day = '2026-10-'.(14 + $i);
                $sid = DB::table('class_sessions')->insertGetId(['group_id' => $g->id, 'starts_at' => "$day 14:00:00",
                    'ends_at' => "$day 15:00:00", 'cancelled' => false, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $st->id, 'status' => $status, 'marked_by' => $t->id, 'marked_at' => now()]);
            }
            // Belgilanmagan dars
            DB::table('class_sessions')->insert(['group_id' => $g->id, 'starts_at' => '2026-10-20 14:00:00', 'ends_at' => '2026-10-20 15:00:00',
                'cancelled' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_admin_sees_all_groups_with_risk_list(): void
    {
        Sanctum::actingAs($this->admin);
        $r = $this->getJson('/v1/reports/attendance?from=2026-10-01&to=2026-10-31')->assertOk()
            ->assertJsonPath('total.sessions', 8)->assertJsonPath('total.students', 2)
            ->assertJsonPath('total.unmarked_sessions', 2)
            ->assertJsonPath('by_group.0.group', 'G1'); // eng past foiz birinchi
        $this->assertEquals(25.0, $r->json('by_group.0.rate'));
        $this->assertCount(1, $r->json('risk'));
        $this->assertStringStartsWith('S-G1', $r->json('risk.0.name'));
        $this->assertCount(4, $r->json('by_day'));

        $this->getJson('/v1/reports/overview?from=2026-10-01&to=2026-10-31')->assertJsonPath('groups', 2)->assertJsonPath('sessions', 10);
        $this->getJson('/v1/reports/quizzes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_teacher_sees_only_own_groups_and_filters_work(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->getJson('/v1/reports/attendance?from=2026-10-01&to=2026-10-31')
            ->assertJsonPath('total.sessions', 4)->assertJsonCount(1, 'by_group');

        Sanctum::actingAs($this->admin);
        $this->getJson('/v1/reports/attendance?from=2026-10-01&to=2026-10-31&teacher_id='.$this->otherTeacher->id)
            ->assertJsonPath('by_group.0.group', 'G2')->assertJsonCount(0, 'risk');
    }

    public function test_csv_export_and_permissions(): void
    {
        Sanctum::actingAs($this->admin);
        $csv = $this->get('/v1/reports/attendance.csv?from=2026-10-01&to=2026-10-31')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('S-G1', $csv);
        $this->assertStringContainsString('kelmadi', $csv);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/v1/reports/attendance')->assertForbidden();
    }
}
