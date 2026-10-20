<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sprint 3 #19: ko'rsatkichlar (oylar bo'yicha, SQL yig'indilar) va drill-down. */
class KpiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $teacher2;

    private int $g1;

    private int $g2;

    /** @var list<User> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create(['first_name' => 'Olim', 'last_name' => 'A']);
        $this->teacher2 = User::factory()->role(Role::Teacher)->create(['first_name' => 'Nodira', 'last_name' => 'B']);
        $course = Course::create(['title' => 'Ingliz', 'created_by' => $this->admin->id]);
        $mk = fn ($name, $t) => DB::table('study_groups')->insertGetId(['name' => $name, 'course_id' => $course->id, 'branch_id' => \App\Models\Branch::primaryId(),
            'teacher_id' => $t->id, 'start_date' => '2026-08-01', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->g1 = $mk('ENG-1', $this->teacher);
        $this->g2 = $mk('ENG-2', $this->teacher2);

        // A: avgustdan beri; B: sentyabrda qo'shildi, oktyabrda chiqdi; C: oktyabrda qo'shildi (ENG-2).
        foreach (['A' => [$this->g1, '2026-08-05', null], 'B' => [$this->g1, '2026-09-10', '2026-10-05'], 'C' => [$this->g2, '2026-10-02', null]] as $n => [$g, $in, $out]) {
            $s = User::factory()->create(['first_name' => $n]);
            $this->students[$n] = $s;
            DB::table('group_members')->insert(['group_id' => $g, 'student_id' => $s->id, 'joined_at' => $in.' 09:00:00', 'left_at' => $out ? $out.' 09:00:00' : null]);
        }

        // Mashg'ulotlar: sentyabr — 1 ta (90 daq), oktyabr — 2 ta ENG-1 (90 daq), 1 ta ENG-2 (60 daq), 1 ta bekor.
        $session = fn ($g, $start, $min, $cancelled = false) => DB::table('class_sessions')->insertGetId(['group_id' => $g, 'starts_at' => $start,
            'ends_at' => Carbon::parse($start)->addMinutes($min)->format('Y-m-d H:i:s'), 'cancelled' => $cancelled, 'created_at' => now(), 'updated_at' => now()]);
        $sep = $session($this->g1, '2026-09-15 14:00:00', 90);
        $oct1 = $session($this->g1, '2026-10-06 14:00:00', 90);
        $session($this->g1, '2026-10-13 14:00:00', 90);
        $session($this->g2, '2026-10-14 10:00:00', 60);
        $session($this->g1, '2026-10-20 14:00:00', 90, true);
        $session($this->g1, '2026-10-27 14:00:00', 90); // kelajakda — soatlarga kirmaydi

        $mark = fn ($sid, $stu, $st) => DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $stu->id, 'status' => $st, 'marked_by' => $this->teacher->id, 'marked_at' => now()]);
        $mark($sep, $this->students['A'], 'PRESENT');
        $mark($sep, $this->students['B'], 'ABSENT');
        $mark($oct1, $this->students['A'], 'LATE');
        $mark($oct1, $this->students['B'], 'EXCUSED');

        // Test: oktyabrda 2 urinish, 1 tasi o'tdi.
        $quiz = DB::table('quizzes')->insertGetId(['course_id' => $course->id, 'title' => 'Q', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([true, false] as $passed) {
            DB::table('quiz_attempts')->insert(['quiz_id' => $quiz, 'student_id' => $this->students['A']->id, 'started_at' => '2026-10-10 10:00:00',
                'submitted_at' => '2026-10-10 10:20:00', 'percent' => $passed ? 80 : 40, 'passed' => $passed]);
        }

        // Uyga vazifa: oktyabr 15 — ENG-1 da faqat A a'zo (B chiqib ketgan), A topshirgan va 4/5 olgan.
        $as = DB::table('assignments')->insertGetId(['group_id' => $this->g1, 'title' => 'HW', 'due_at' => '2026-10-15 18:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('assignment_submissions')->insert(['assignment_id' => $as, 'student_id' => $this->students['A']->id, 'submitted_at' => '2026-10-15 10:00:00',
            'score' => 4, 'created_at' => now(), 'updated_at' => now()]);

        // To'lovlar.
        DB::table('billing_entries')->insert([
            ['student_id' => $this->students['A']->id, 'type' => 'CHARGE', 'amount' => -400000, 'entry_date' => '2026-10-01', 'created_at' => now(), 'updated_at' => now(), 'voided_at' => null],
            ['student_id' => $this->students['A']->id, 'type' => 'PAYMENT', 'amount' => 300000, 'entry_date' => '2026-10-03', 'created_at' => now(), 'updated_at' => now(), 'voided_at' => null],
            ['student_id' => $this->students['A']->id, 'type' => 'PAYMENT', 'amount' => 999999, 'entry_date' => '2026-10-04', 'created_at' => now(), 'updated_at' => now(), 'voided_at' => now()],
        ]);
    }

    public function test_monthly_series_for_admin(): void
    {
        Sanctum::actingAs($this->admin);
        $r = $this->getJson('/v1/reports/kpi?months=3')->assertOk();
        $this->assertSame(['2026-08', '2026-09', '2026-10'], $r->json('months'));
        [$aug, $sep, $oct] = $r->json('series');

        $this->assertSame([1, 1, 0], [$aug['active_students'], $aug['new_students'], $aug['left_students']]);
        $this->assertSame([2, 1, 0], [$sep['active_students'], $sep['new_students'], $sep['left_students']]);
        $this->assertSame([2, 1, 1], [$oct['active_students'], $oct['new_students'], $oct['left_students']]);
        $this->assertEquals(50, $oct['churn_percent']);
        $this->assertTrue($oct['partial']);

        $this->assertEquals(50, $sep['attendance_rate']);
        $this->assertEquals(100, $oct['attendance_rate']); // LATE — keldi, EXCUSED hisobga kirmaydi
        $this->assertNull($aug['attendance_rate']);

        $this->assertSame(2, $oct['quiz_attempts']);
        $this->assertEquals(50, $oct['quiz_pass_rate']);
        $this->assertSame([1, 1], [$oct['homework_expected'], $oct['homework_submitted']]);
        $this->assertEquals(100, $oct['homework_rate']);
        $this->assertEquals(80, $oct['homework_avg_percent']);

        $this->assertEquals(1.5, $sep['teacher_hours']);
        $this->assertEquals(4, $oct['teacher_hours']); // 90 + 90 + 60 daqiqa
        $this->assertSame(3, $oct['sessions']);
        $olim = collect($r->json('teachers'))->firstWhere('name', 'Olim A');
        $this->assertEquals(3, $olim['hours']['2026-10']);
        $this->assertEquals(1, collect($r->json('teachers'))->firstWhere('name', 'Nodira B')['hours']['2026-10']);

        $money = $r->json('money');
        $this->assertSame(300000, $money['series'][2]['income']);
        $this->assertSame(400000, $money['series'][2]['charged']);
        $this->assertSame(100000, $money['debt_total']);
        $this->assertSame(1, $money['debtors']);
    }

    public function test_teacher_sees_only_own_groups_and_no_money(): void
    {
        Sanctum::actingAs($this->teacher);
        $r = $this->getJson('/v1/reports/kpi?months=2')->assertOk();
        $this->assertNull($r->json('money'));
        $oct = $r->json('series.1');
        $this->assertSame(1, $oct['active_students']); // C (ENG-2) kirmaydi
        $this->assertEquals(3, $oct['teacher_hours']);
        $this->assertCount(1, $r->json('teachers'));

        Sanctum::actingAs($this->students['A']);
        $this->getJson('/v1/reports/kpi')->assertForbidden();
    }

    public function test_drill_down_lists(): void
    {
        Sanctum::actingAs($this->admin);
        $new = $this->getJson('/v1/reports/kpi/students?type=new&month=2026-10')->assertOk()->json('data');
        $this->assertSame(['C'], array_map(fn ($r) => explode(' ', $r['name'])[0], $new));
        $left = $this->getJson('/v1/reports/kpi/students?type=left&month=2026-10')->assertOk()->json('data');
        $this->assertSame('2026-10-05', $left[0]['left_at']);
        $this->getJson('/v1/reports/kpi/students?type=active&month=2026-09')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/v1/reports/kpi/students?type=active&month=2026-10&group_id={$this->g2}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/v1/reports/kpi/students?type=bad&month=2026-10')->assertUnprocessable();
    }
}
