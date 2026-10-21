<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Lead;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Foydalanuvchi qidiruvi, o'quvchi profili, boshlash ro'yxati va umumiy qidiruv. */
class ProfileSearchSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $outsider;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create(['first_name' => 'Ustoz']);
        $this->student = User::factory()->create(['first_name' => 'Aziz', 'last_name' => 'Karimov', 'phone' => '+998901234567']);
        $this->outsider = User::factory()->create(['first_name' => 'Dilnoza', 'phone' => '+998935554433']);
        $course = Course::create(['title' => 'Ingliz tili', 'created_by' => $this->admin->id, 'published' => true]);
        $this->group = StudyGroup::create(['name' => 'ENG-1', 'course_id' => $course->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-09-01', 'active' => true]);
        Sanctum::actingAs($this->admin);
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
    }

    public function test_user_search_matches_any_phone_format_and_multi_word_names(): void
    {
        $this->getJson('/v1/users?role=STUDENT&q=90 123')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->student->id);
        $this->getJson('/v1/users?role=STUDENT&q=aziz karim')->assertJsonCount(1, 'data');
        $this->getJson('/v1/users?role=STUDENT&q=+998 90 123 45 67')->assertJsonCount(1, 'data');
        $this->getJson('/v1/users?role=STUDENT&q=karim aziz')->assertJsonCount(1, 'data');
        $this->getJson('/v1/users?role=STUDENT&q=aziz dilnoza')->assertJsonCount(0, 'data');
    }

    public function test_profile_overview_shows_frozen_status_attendance_quizzes_and_balance(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members/{$this->student->id}/freeze", ['from' => '2026-10-20', 'to' => '2026-10-25', 'reason' => 'Kasallik'])->assertOk();
        $s = ClassSession::create(['group_id' => $this->group->id, 'starts_at' => '2026-10-15 14:00:00', 'ends_at' => '2026-10-15 15:00:00']);
        DB::table('attendances')->insert(['session_id' => $s->id, 'student_id' => $this->student->id, 'status' => 'ABSENT', 'marked_by' => $this->teacher->id, 'marked_at' => now()]);
        $quizId = DB::table('quizzes')->insertGetId(['course_id' => $this->group->course_id, 'title' => 'Unit 1', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('quiz_attempts')->insert(['quiz_id' => $quizId, 'student_id' => $this->student->id, 'submitted_at' => now(), 'score' => 8, 'max_score' => 10, 'percent' => 80, 'passed' => true]);

        $o = $this->getJson("/v1/users/{$this->student->id}/profile")->assertOk()->json('overview');
        $this->assertSame(['ENG-1', 'FROZEN', '2026-10-25'], [$o['groups'][0]['name'], $o['groups'][0]['status'], $o['groups'][0]['frozen_until']]);
        $this->assertSame(1, $o['attendance']['counts']['ABSENT']);
        $this->assertSame('Unit 1', $o['quizzes'][0]['title']);
        $this->assertSame(0, $o['balance']['balance']);
    }

    public function test_student_profile_access_by_role(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members/{$this->student->id}/leave", ['reason' => 'Narx qimmat'])->assertOk();
        Lead::create(['name' => 'Aziz', 'phone' => '+998901234567', 'source' => 'site', 'status' => 'ENROLLED', 'student_id' => $this->student->id]);

        $r = $this->getJson("/v1/users/{$this->student->id}/profile")->assertOk();
        $this->assertSame('LEFT', $r->json('timeline.0.outcome'));
        $this->assertSame('Narx qimmat', $r->json('timeline.0.reason'));
        $this->assertCount(1, $r->json('leads'));
        $this->assertTrue($r->json('can_manage'));

        // O'qituvchi: avval o'z guruhida o'qigan o'quvchini ko'radi (arizalarsiz), boshqasini — yo'q.
        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/users/{$this->student->id}/profile")->assertOk()->assertJsonCount(0, 'leads')->assertJsonPath('can_manage', false);
        $stranger = User::factory()->create();
        $this->getJson("/v1/users/{$stranger->id}/profile")->assertForbidden();
        Sanctum::actingAs($this->outsider);
        $this->getJson("/v1/users/{$this->student->id}/profile")->assertForbidden();
    }

    public function test_setup_checklist_ticks_from_real_data(): void
    {
        $super = User::factory()->role(Role::SuperAdmin)->create();
        Sanctum::actingAs($super);
        $steps = collect($this->getJson('/v1/dashboard/summary')->assertOk()->json('setup.steps'))->pluck('done', 'key');
        $this->assertSame(['site' => false, 'bot' => false, 'telegram' => false, 'billing' => false, 'course' => true, 'teacher' => true,
            'group' => true, 'schedule' => false, 'students' => true], $steps->all());

        app(Settings::class)->set(['site.contact_phone' => '+998712000000', 'site.address' => 'Toshkent', 'billing.monthly_fee' => 500000], $super->id);
        DB::table('group_schedules')->insert(['group_id' => $this->group->id, 'weekday' => 1, 'start_time' => '14:00', 'end_time' => '15:00']);
        $setup = $this->getJson('/v1/dashboard/summary')->json('setup');
        $this->assertSame(7, $setup['done']);
        $this->assertSame(9, $setup['total']);

        Sanctum::actingAs($this->teacher);
        $this->assertNull($this->getJson('/v1/dashboard/summary')->json('setup'));
        app(Settings::class)->set(['ui.setup_checklist' => false], null);
        Sanctum::actingAs($super);
        $this->assertNull($this->getJson('/v1/dashboard/summary')->json('setup'));
    }

    public function test_search_ignores_uzbek_apostrophes(): void
    {
        $oralov = User::factory()->create(['first_name' => "O'g'abek", 'last_name' => "O'ralov", 'phone' => '+998973580208']);
        Course::create(['title' => "To'g'ri yozish", 'created_by' => $this->admin->id, 'published' => true]);
        Lead::create(['name' => "Sho'xrux", 'phone' => '+998901119999', 'source' => 'site', 'status' => 'NEW']);

        $titles = fn (string $q) => collect($this->getJson('/v1/search?q='.urlencode($q))->assertOk()->json('data'))->pluck('title')->all();
        $this->assertContains("O'g'abek O'ralov", $titles('oralov'));
        $this->assertContains("O'g'abek O'ralov", $titles('o‘ralov'));      // boshqa apostrof belgisi
        $this->assertContains("O'g'abek O'ralov", $titles("ogabek o'ralov"));
        $this->assertContains("To'g'ri yozish", $titles('togri'));
        $this->assertContains("Sho'xrux", $titles('shoxrux'));
        $this->getJson('/v1/users?q=oralov')->assertJsonPath('data.0.id', $oralov->id);
        $this->getJson('/v1/leads?q=shoxrux')->assertJsonCount(1, 'data');
        $this->assertSame([], $titles("''"));                              // faqat apostrof — shart qo'shilmaydi, lekin hech narsa topilmaydi
    }

    public function test_global_search_is_scoped_by_role(): void
    {
        Lead::create(['name' => 'Aziza', 'phone' => '+998901112233', 'source' => 'site', 'status' => 'NEW']);
        $outsider = User::factory()->create(['first_name' => 'Azizbek', 'phone' => '+998909998877']);

        $types = fn ($r) => collect($r->json('data'))->map(fn ($x) => $x['type'].':'.$x['title'])->all();
        $r = $this->getJson('/v1/search?q=aziz')->assertOk();
        $this->assertContains('student:Aziz Karimov', $types($r));
        $this->assertContains('student:'.trim($outsider->first_name.' '.$outsider->last_name), $types($r));
        $this->assertContains('lead:Aziza', $types($r));
        $this->assertSame("/dashboard/users/{$this->student->id}", collect($r->json('data'))->firstWhere('id', $this->student->id)['href']);
        $this->assertContains('group:ENG-1', $types($this->getJson('/v1/search?q=eng')));
        $this->assertContains('course:Ingliz tili', $types($this->getJson('/v1/search?q=ingliz')));
        $this->assertSame(['student:Aziz Karimov'], $types($this->getJson('/v1/search?q=90 123 45')));

        Sanctum::actingAs($this->teacher);
        $r = $types($this->getJson('/v1/search?q=aziz'));
        $this->assertSame(['student:Aziz Karimov'], $r); // begona o'quvchi va arizalar ko'rinmaydi

        Sanctum::actingAs($this->student);
        $this->assertSame([], $types($this->getJson('/v1/search?q=aziz')));
        $this->assertSame(['group:ENG-1'], $types($this->getJson('/v1/search?q=eng')));
        $this->getJson('/v1/search?q=a')->assertStatus(422);
    }
}
