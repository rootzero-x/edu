<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use App\Support\Heartbeat;
use App\Support\Settings;
use App\Support\SystemStatus;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** #2: cron yurak urishi, vazifalar holati va admin "Tizim holati" kartasi. */
class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->admin = User::factory()->role(Role::Admin)->create();
        DB::table('telegram_links')->insert(['user_id' => $this->admin->id, 'telegram_id' => 900, 'chat_id' => 900, 'linked_at' => now()]);
        app(Settings::class)->set(['telegram.admin_chat_ids' => [900]], null);
    }

    private function alerts(string $needle): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/sendMessage') && str_contains((string) $p[0]['text'], $needle))->count();
    }

    public function test_scheduler_runs_heartbeat_and_tracks_jobs(): void
    {
        $events = collect(app(Schedule::class)->events());
        $this->assertTrue($events->contains(fn ($e) => $e->description === 'tisu:heartbeat'));
        foreach (array_keys(Heartbeat::JOBS) as $job) {
            $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e->command, $job)), "$job jadvalda yo'q");
        }

        $this->artisan('schedule:run')->assertSuccessful();
        $this->assertSame(now()->toIso8601String(), SystemStatus::get('scheduler.tick'));
        // Har daqiqalik navbat vazifasi muvaffaqiyatli o'tdi va qayd etildi.
        $this->assertNotNull(SystemStatus::get('job.tisu:outbox-drain')['last_ok_at'] ?? null);
    }

    public function test_stale_cron_alerts_admins_once_per_throttle_window(): void
    {
        SystemStatus::put('scheduler.tick', now()->subMinutes(25)->toIso8601String());
        Heartbeat::checkFromWeb();
        $this->assertSame(1, $this->alerts('25 daqiqadan beri'));

        // 5 daqiqalik tekshiruv oynasi va soatlik ogohlantirish chegarasi — takror yo'q.
        Heartbeat::checkFromWeb();
        Carbon::setTestNow(now()->addMinutes(10));
        Heartbeat::checkFromWeb();
        $this->assertSame(1, $this->alerts('daqiqadan beri ishlamayapti'));
    }

    public function test_fresh_cron_and_never_run_do_not_alert(): void
    {
        Heartbeat::checkFromWeb(); // hech qachon ishlamagan — faqat kartada
        SystemStatus::put('scheduler.tick', now()->subMinute()->toIso8601String());
        Carbon::setTestNow(now()->addMinutes(6));
        SystemStatus::put('scheduler.tick', now()->toIso8601String());
        Heartbeat::checkFromWeb();
        $this->assertSame(0, $this->alerts('ishlamayapti'));
    }

    public function test_overdue_job_alerts_with_last_success(): void
    {
        SystemStatus::put('scheduler.first_tick', now()->subDays(5)->toIso8601String());
        foreach (array_keys(Heartbeat::JOBS) as $job) {
            Heartbeat::ok($job);
        }
        // Zaxira 3 kundan beri muvaffaqiyatli o'tmagan (kutilgan: kuniga 1 marta, chegara — 2 kun).
        SystemStatus::put('job.tisu:backup', ['last_ok_at' => now()->subDays(3)->toIso8601String(), 'last_error' => 'mysqldump: access denied']);
        Heartbeat::tick();
        $this->assertSame(1, $this->alerts('tisu:backup'));
        $this->assertSame(1, $this->alerts('access denied'));
        Heartbeat::tick();
        $this->assertSame(1, $this->alerts('tisu:backup'), 'cheklangan');
    }

    public function test_status_card_for_admins_only_with_warnings(): void
    {
        SystemStatus::put('backup.last', ['at' => now()->toIso8601String(), 'ok' => true, 'file' => 'db.sql.gz.enc', 'encrypted' => true]);
        DB::table('telegram_links')->where('user_id', $this->admin->id)->update(['blocked_at' => now()]);
        User::factory()->create(['active' => false])->forceFill(['pending_at' => now()])->save();
        // Eski DB qiymati: o'qituvchida course.manage qolgan — ogohlantirish.
        app(Settings::class)->set(['roles.permissions' => ['TEACHER' => ['course.manage', 'course.view']]], null);

        Sanctum::actingAs($this->admin);
        $res = $this->getJson('/v1/system/status')->assertOk()
            ->assertJsonPath('scheduler.stale', true)->assertJsonPath('backup.file', 'db.sql.gz.enc')
            ->assertJsonPath('telegram.blocked_chats', 1)->assertJsonPath('users.pending', 1);
        $this->assertContains('cron_never', $res->json('warnings'));
        $this->assertContains('teacher_course_manage', $res->json('warnings'));
        $this->assertContains('backup_unencrypted', $res->json('warnings'));

        Sanctum::actingAs(User::factory()->role(Role::Teacher)->create());
        $this->getJson('/v1/system/status')->assertForbidden();
    }

    public function test_health_reports_cron_state_without_secrets(): void
    {
        $this->getJson('/health')->assertOk()->assertJsonPath('cron', 'never');
        SystemStatus::put('scheduler.tick', now()->subMinutes(30)->toIso8601String());
        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('cron', 'stale')
            ->assertJsonPath('scheduler.minutes_since', 30);
        SystemStatus::put('scheduler.tick', now()->toIso8601String());
        $this->getJson('/health')->assertJsonPath('cron', 'ok');
    }
}
