<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\GroupSchedule;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private StudyGroup $group;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 13:30:00'); // chorshanba
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->teacher = User::factory()->role(Role::Teacher)->create(['first_name' => 'Ustoz']);
        $this->student = User::factory()->create(['first_name' => 'Ali']);
        foreach ([$this->teacher, $this->student] as $i => $u) {
            DB::table('telegram_links')->insert(['user_id' => $u->id, 'telegram_id' => 100 + $i, 'chat_id' => 100 + $i, 'linked_at' => now()]);
        }
        $this->group = StudyGroup::create(['name' => 'ENG-1', 'course_id' => Course::create(['title' => 'Ingliz', 'created_by' => $this->teacher->id])->id,
            'branch_id' => Branch::create(['name' => 'B'])->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-19', 'room' => '101']);
        GroupSchedule::create(['group_id' => $this->group->id, 'weekday' => 3, 'start_time' => '14:00', 'end_time' => '15:00']);
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $this->student->id, 'joined_at' => now()]);
    }

    public function test_generate_sessions_respects_setting(): void
    {
        app(Settings::class)->set(['attendance.sessions_ahead_days' => 14], null);
        $this->artisan('tisu:generate-sessions')->assertSuccessful();
        $this->assertSame(3, DB::table('class_sessions')->count()); // 21, 28 okt, 4 noy
        $this->artisan('tisu:generate-sessions')->assertSuccessful();
        $this->assertSame(3, DB::table('class_sessions')->count());

        app(Settings::class)->set(['automation.generate_sessions' => false], null);
        DB::table('class_sessions')->delete();
        $this->artisan('tisu:generate-sessions')->assertSuccessful();
        $this->assertSame(0, DB::table('class_sessions')->count());
    }

    public function test_lesson_reminder_sent_once_within_window(): void
    {
        $this->artisan('tisu:generate-sessions');
        $this->artisan('tisu:remind-lessons')->assertSuccessful();
        $this->artisan('tisu:remind-lessons')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'Ali') && str_contains($r['text'], '14:00') && str_contains($r['text'], '101'));

        // Oyna sozlamadan: 20 daqiqa bo'lsa, 14:00 dagi dars hali oynaga kirmaydi.
        DB::table('notifications_log')->delete();
        app(Settings::class)->set(['automation.lesson_reminder_minutes' => 20], null);
        $this->artisan('tisu:remind-lessons');
        Http::assertSentCount(1);
    }

    public function test_unmarked_reminder_to_teacher(): void
    {
        $this->artisan('tisu:generate-sessions');
        Carbon::setTestNow('2026-10-21 15:40:00');
        $this->artisan('tisu:remind-unmarked')->assertSuccessful();
        $this->artisan('tisu:remind-unmarked');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'Ustoz') && str_contains($r['text'], 'ENG-1'));

        // Belgilangan dars uchun eslatma bo'lmaydi.
        DB::table('notifications_log')->delete();
        $sid = DB::table('class_sessions')->where('starts_at', '2026-10-21 14:00:00')->value('id');
        DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $this->student->id, 'status' => 'PRESENT', 'marked_by' => $this->teacher->id, 'marked_at' => now()]);
        $this->artisan('tisu:remind-unmarked');
        Http::assertSentCount(1);
    }

    public function test_cleanup(): void
    {
        DB::table('notifications_log')->insert(['user_id' => $this->student->id, 'channel' => 'telegram', 'kind' => 'x', 'payload' => '{}',
            'created_at' => now()->subDays(400), 'updated_at' => now()]);
        $this->artisan('tisu:cleanup')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications_log')->count());
    }
}
