<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use App\Support\ErrorAlerter;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_error_alerts_admin_once_per_window(): void
    {
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $admin = User::factory()->role(Role::SuperAdmin)->create();
        DB::table('telegram_links')->insert(['user_id' => $admin->id, 'telegram_id' => 777, 'chat_id' => 777, 'linked_at' => now()]);
        app(Settings::class)->set(['telegram.admin_chat_ids' => [777, 888]], null); // 888 — bog'lanmagan, yuborilmaydi

        $e = new RuntimeException("Disk to'ldi");
        ErrorAlerter::report($e);
        ErrorAlerter::report($e);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => (int) $r['chat_id'] === 777 && str_contains($r['text'], "Disk to'ldi"));

        app(Settings::class)->set(['alerts.errors_enabled' => false], null);
        ErrorAlerter::report(new RuntimeException('Boshqa xato'));
        Http::assertSentCount(1);
    }

    public function test_backup_command_is_mysql_only_and_respects_switch(): void
    {
        app(Settings::class)->set(['backup.enabled' => false], null);
        $this->artisan('tisu:backup')->expectsOutputToContain("O'chirilgan")->assertSuccessful();
        $this->artisan('tisu:backup --force')->expectsOutputToContain('MySQL')->assertSuccessful();
    }
}
