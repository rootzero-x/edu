<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Course;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sprint 3 #14: xonalar, jadval to'qnashuvlari va taqvim. */
class CalendarRoomsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $teacher2;

    private int $courseId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00'); // chorshanba
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create(['first_name' => 'Olim']);
        $this->teacher2 = User::factory()->role(Role::Teacher)->create(['first_name' => 'Nodira']);
        $this->courseId = Course::create(['title' => 'Ingliz', 'created_by' => $this->admin->id])->id;
        Sanctum::actingAs($this->admin);
    }

    private function room(string $name): int
    {
        return $this->postJson('/v1/rooms', ['name' => $name, 'capacity' => 12])->assertCreated()->json('room.id');
    }

    private function group(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/v1/groups', $over + [
            'name' => 'G-'.uniqid(), 'course_id' => $this->courseId, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01',
            'schedules' => [['weekday' => 1, 'start_time' => '14:00', 'end_time' => '15:30']],
        ]);
    }

    public function test_rooms_crud_and_permissions(): void
    {
        $id = $this->room('101');
        $this->postJson('/v1/rooms', ['name' => '101'])->assertUnprocessable();
        $this->patchJson("/v1/rooms/$id", ['name' => '101-A', 'active' => false])->assertOk()->assertJsonPath('room.name', '101-A');
        $this->getJson('/v1/rooms')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/v1/rooms?all=1')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->teacher);
        $this->postJson('/v1/rooms', ['name' => '202'])->assertForbidden();
        $this->getJson('/v1/rooms?all=1')->assertOk();

        Sanctum::actingAs($this->admin);
        $gid = $this->group(['room_id' => $id])->assertCreated()->json('group.id');
        $this->assertSame('101-A', DB::table('study_groups')->where('id', $gid)->value('room'));
        $this->deleteJson("/v1/rooms/$id")->assertStatus(409);
        // Nom o'zgarsa, guruhdagi nusxa ham yangilanadi (bot eslatmalari uchun).
        $this->patchJson("/v1/rooms/$id", ['name' => 'Katta xona'])->assertOk();
        $this->assertSame('Katta xona', DB::table('study_groups')->where('id', $gid)->value('room'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'room.update', 'entity_id' => $id]);
    }

    public function test_legacy_free_text_room_becomes_a_room(): void
    {
        $gid = $this->group(['room' => ' 305 '])->assertCreated()->json('group.id');
        $roomId = DB::table('rooms')->where('name', '305')->value('id');
        $this->assertNotNull($roomId);
        $this->assertSame($roomId, DB::table('study_groups')->where('id', $gid)->value('room_id'));
        // Shu nom (katta-kichik harf farqi bilan) — o'sha xona.
        $this->group(['room' => '305', 'teacher_id' => $this->teacher2->id, 'schedules' => []])->assertCreated();
        $this->assertSame(1, DB::table('rooms')->count());
    }

    public function test_teacher_clash_names_the_conflicting_group(): void
    {
        $this->group(['name' => 'ENG-1'])->assertCreated();
        $r = $this->group(['name' => 'ENG-2', 'schedules' => [['weekday' => 1, 'start_time' => '15:00', 'end_time' => '16:00']]])
            ->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->assertStringContainsString('ENG-1', $r->json('errors.teacher_id.0'));
        $this->assertStringContainsString('Dushanba 14:00–15:30', $r->json('errors.teacher_id.0'));

        // Chegaradosh vaqt (15:30 da boshlanadi) va boshqa kun — to'qnashuv emas.
        $this->group(['name' => 'ENG-3', 'schedules' => [['weekday' => 1, 'start_time' => '15:30', 'end_time' => '17:00']]])->assertCreated();
        $this->group(['name' => 'ENG-4', 'schedules' => [['weekday' => 2, 'start_time' => '14:00', 'end_time' => '15:30']]])->assertCreated();
    }

    public function test_room_clash_with_another_teacher(): void
    {
        $room = $this->room('101');
        $this->group(['name' => 'ENG-1', 'room_id' => $room])->assertCreated();
        $r = $this->group(['name' => 'MATH-1', 'room_id' => $room, 'teacher_id' => $this->teacher2->id])
            ->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->assertStringContainsString('«101»', $r->json('errors.room_id.0'));
        $this->assertStringContainsString('ENG-1', $r->json('errors.room_id.0'));

        // Sozlamada o'chirilsa — ruxsat.
        app(Settings::class)->set(['calendar.check_room_clash' => false], null);
        $this->group(['name' => 'MATH-1', 'room_id' => $room, 'teacher_id' => $this->teacher2->id])->assertCreated();
    }

    public function test_inactive_groups_and_non_overlapping_dates_do_not_clash(): void
    {
        $old = $this->group(['name' => 'OLD', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30'])->assertCreated()->json('group.id');
        $this->group(['name' => 'NEW', 'start_date' => '2026-09-01'])->assertCreated();
        // Eski guruh muddati uzaytirilsa — yangisi bilan to'qnashadi.
        $this->patchJson("/v1/groups/$old", ['end_date' => '2026-12-31'])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->patchJson("/v1/groups/$old", ['active' => false])->assertOk();
        $this->group(['name' => 'NEW-2', 'start_date' => '2026-09-01', 'teacher_id' => $this->teacher2->id])->assertCreated();
    }

    public function test_self_overlap_and_teacher_change_on_update(): void
    {
        $this->group(['schedules' => [['weekday' => 3, 'start_time' => '10:00', 'end_time' => '11:00'], ['weekday' => 3, 'start_time' => '10:30', 'end_time' => '12:00']]])
            ->assertUnprocessable()->assertJsonValidationErrors('schedules');

        $this->group(['name' => 'A'])->assertCreated();
        $b = $this->group(['name' => 'B', 'teacher_id' => $this->teacher2->id])->assertCreated()->json('group.id');
        $this->patchJson("/v1/groups/$b", ['teacher_id' => $this->teacher->id])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
        $this->patchJson("/v1/groups/$b", ['name' => 'B2'])->assertOk();
        $log = json_decode(DB::table('audit_logs')->where('action', 'group.update')->latest('id')->value('data'), true);
        $this->assertSame(['name' => 'B'], $log['before']);
        $this->assertSame(['name' => 'B2'], $log['after']);
    }

    public function test_moving_a_session_onto_a_busy_slot_is_rejected(): void
    {
        $a = $this->group(['name' => 'A'])->assertCreated()->json('group.id');
        $b = $this->group(['name' => 'B', 'schedules' => [['weekday' => 2, 'start_time' => '14:00', 'end_time' => '15:30']]])->assertCreated()->json('group.id');
        $this->postJson("/v1/groups/$a/sessions/generate", ['from' => '2026-10-26', 'to' => '2026-10-26'])->assertJsonPath('created', 1);
        $this->postJson("/v1/groups/$b/sessions/generate", ['from' => '2026-10-27', 'to' => '2026-10-27'])->assertJsonPath('created', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session.generate', 'entity_id' => $a]);
        $sb = DB::table('class_sessions')->where('group_id', $b)->value('id');

        $r = $this->patchJson("/v1/sessions/$sb", ['starts_at' => '2026-10-26T14:30:00+05:00', 'ends_at' => '2026-10-26T16:00:00+05:00'])
            ->assertUnprocessable();
        $this->assertStringContainsString('«A»', $r->json('errors.starts_at.0'));
        $this->assertStringContainsString('26.10.2026', $r->json('errors.starts_at.0'));
        $this->patchJson("/v1/sessions/$sb", ['starts_at' => '2026-10-26T16:00:00+05:00', 'ends_at' => '2026-10-26T17:00:00+05:00'])->assertOk();
    }

    public function test_calendar_returns_sessions_with_filters_and_scoping(): void
    {
        $room = $this->room('101');
        $a = $this->group(['name' => 'A', 'room_id' => $room])->assertCreated()->json('group.id');
        $b = $this->group(['name' => 'B', 'teacher_id' => $this->teacher2->id])->assertCreated()->json('group.id');
        $this->postJson("/v1/groups/$a/sessions/generate", ['from' => '2026-10-19', 'to' => '2026-11-15']);
        $this->postJson("/v1/groups/$b/sessions/generate", ['from' => '2026-10-19', 'to' => '2026-11-15']);
        $student = User::factory()->create();
        $this->postJson("/v1/groups/$a/members", ['student_id' => $student->id])->assertCreated();

        $r = $this->getJson('/v1/calendar?from=2026-10-19&to=2026-10-25')->assertOk();
        $this->assertCount(2, $r->json('data'));
        $this->assertSame('101', collect($r->json('data'))->firstWhere('group_name', 'A')['room']);
        $this->assertCount(2, $r->json('filters.teachers'));
        $this->assertCount(1, $r->json('filters.rooms'));

        $this->getJson("/v1/calendar?from=2026-10-19&to=2026-11-15&teacher_id={$this->teacher2->id}")->assertOk()->assertJsonCount(4, 'data');
        $this->getJson("/v1/calendar?from=2026-10-19&to=2026-11-15&room_id=$room")->assertOk()->assertJsonCount(4, 'data');
        $this->getJson("/v1/calendar?from=2026-10-19&to=2026-11-15&group_id=$b")->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/v1/calendar?from=2026-01-01&to=2026-12-31')->assertUnprocessable();

        // Bekor qilingan mashg'ulot ham ko'rinadi (ustidan chizilgan holda).
        $sid = DB::table('class_sessions')->where('group_id', $a)->orderBy('starts_at')->value('id');
        $this->patchJson("/v1/sessions/$sid", ['cancelled' => true])->assertOk();

        Sanctum::actingAs($student);
        $r = $this->getJson('/v1/calendar?from=2026-10-19&to=2026-11-15')->assertOk();
        $this->assertCount(4, $r->json('data'));
        $this->assertSame(['A'], collect($r->json('data'))->pluck('group_name')->unique()->values()->all());
        $this->assertTrue($r->json('data.0.cancelled'));
        $this->assertNull($r->json('data.0.marked_count'));
    }
}
