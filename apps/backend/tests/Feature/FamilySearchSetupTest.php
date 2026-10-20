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

/** Ota-onani qidirib bog'lash, "Farzandlarim", o'quvchi profili, boshlash ro'yxati va umumiy qidiruv. */
class FamilySearchSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $parent;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create(['first_name' => 'Ustoz']);
        $this->student = User::factory()->create(['first_name' => 'Aziz', 'last_name' => 'Karimov', 'phone' => '+998901234567']);
        $this->parent = User::factory()->role(Role::Parent)->create(['first_name' => 'Dilnoza', 'phone' => '+998935554433']);
        $course = Course::create(['title' => 'Ingliz tili', 'created_by' => $this->admin->id, 'published' => true]);
        $this->group = StudyGroup::create(['name' => 'ENG-1', 'course_id' => $course->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-09-01', 'active' => true]);
        Sanctum::actingAs($this->admin);
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
    }

    public function test_parent_is_linked_by_searching_the_child_or_created_on_the_fly(): void
    {
        $this->getJson('/v1/users?role=STUDENT&q=90 123')->assertOk()->assertJsonPath('data.0.id', $this->student->id);
        $this->getJson('/v1/users?role=STUDENT&q=aziz karim')->assertJsonCount(1, 'data');
        $this->getJson('/v1/users?role=STUDENT&q=+998 90 123 45 67')->assertJsonCount(1, 'data');

        $this->postJson("/v1/users/{$this->student->id}/parents", ['parent_id' => $this->parent->id])->assertCreated()->assertJson(['created' => false]);
        // Telefon mavjud ota-onaga tegishli — dublikat yaratilmaydi.
        $this->postJson("/v1/users/{$this->student->id}/parents", ['first_name' => 'Boshqa', 'phone' => '93 555 44 33'])->assertCreated()->assertJson(['created' => false]);
        $this->postJson("/v1/users/{$this->student->id}/parents", ['first_name' => 'Otasi', 'phone' => '97 000 11 22'])->assertCreated()->assertJson(['created' => true]);
        $this->assertSame(2, DB::table('parent_child')->where('child_id', $this->student->id)->count());
        $this->postJson("/v1/users/{$this->student->id}/parents", ['first_name' => 'X', 'phone' => $this->teacher->phone])->assertStatus(422);
        $this->postJson("/v1/users/{$this->parent->id}/parents", ['parent_id' => $this->parent->id])->assertStatus(422);
        $this->getJson("/v1/users/{$this->student->id}/parents")->assertJsonCount(2, 'data');
    }

    public function test_farzandlarim_shows_groups_status_attendance_quizzes_and_balance(): void
    {
        DB::table('parent_child')->insert(['parent_id' => $this->parent->id, 'child_id' => $this->student->id]);
        $this->postJson("/v1/groups/{$this->group->id}/members/{$this->student->id}/freeze", ['from' => '2026-10-20', 'to' => '2026-10-25', 'reason' => 'Kasallik'])->assertOk();
        $s = ClassSession::create(['group_id' => $this->group->id, 'starts_at' => '2026-10-15 14:00:00', 'ends_at' => '2026-10-15 15:00:00']);
        DB::table('attendances')->insert(['session_id' => $s->id, 'student_id' => $this->student->id, 'status' => 'ABSENT', 'marked_by' => $this->teacher->id, 'marked_at' => now()]);
        $quizId = DB::table('quizzes')->insertGetId(['course_id' => $this->group->course_id, 'title' => 'Unit 1', 'published' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('quiz_attempts')->insert(['quiz_id' => $quizId, 'student_id' => $this->student->id, 'submitted_at' => now(), 'score' => 8, 'max_score' => 10, 'percent' => 80, 'passed' => true]);

        $other = User::factory()->create();
        Sanctum::actingAs($this->parent);
        $r = $this->getJson('/v1/me/children')->assertOk()->assertJsonCount(1, 'data');
        $child = $r->json('data.0');
        $this->assertSame($this->student->id, $child['id']);
        $this->assertSame(['ENG-1', 'FROZEN', '2026-10-25'], [$child['groups'][0]['name'], $child['groups'][0]['status'], $child['groups'][0]['frozen_until']]);
        $this->assertSame(1, $child['attendance']['counts']['ABSENT']);
        $this->assertSame('Unit 1', $child['quizzes'][0]['title']);
        $this->assertSame(0, $child['balance']['balance']);
        $this->assertNotContains($other->id, array_column($r->json('data'), 'id'));

        Sanctum::actingAs($this->student);
        $this->getJson('/v1/me/children')->assertOk()->assertJsonCount(0, 'data');
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
        Sanctum::actingAs($this->parent);
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
