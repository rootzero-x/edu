<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use App\Support\Settings;
use App\Telegram\Notifier;
use App\Telegram\Outbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** #6: navbat orqali yuborish (workersiz): tezlik, 429 retry_after, 403 — bloklagan chat, qayta urinishlar, tarqatish paketi. */
class OutboxTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    /** chat_id => navbatdagi javoblar (bo'sh bo'lsa ok). */
    private array $script = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 12:00:00');
        config(['services.telegram.bot_token' => '1:T', 'services.telegram.webhook_secret' => self::SECRET]);
        Http::fake(function (HttpRequest $r) {
            $chat = (int) ($r['chat_id'] ?? 0);
            if (! empty($this->script[$chat])) {
                [$code, $body] = array_shift($this->script[$chat]);

                return Http::response($body, $code);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        });
    }

    private function linked(int $chat, array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        DB::table('telegram_links')->insert(['user_id' => $u->id, 'telegram_id' => $chat, 'chat_id' => $chat, 'linked_at' => now()]);

        return $u;
    }

    private function sentTo(int $chat): int
    {
        return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), '/sendMessage') && (int) ($p[0]['chat_id'] ?? 0) === $chat)->count();
    }

    public function test_notifier_queues_and_inline_drain_delivers_and_logs(): void
    {
        $u = $this->linked(11);
        app(Notifier::class)->sendText($u->id, 'test.kind', 'Salom');
        // defer testlarda darhol bajariladi (inline drain).
        $this->assertSame(1, $this->sentTo(11));
        $this->assertSame('sent', DB::table('telegram_outbox')->value('status'));
        $this->assertNotNull(DB::table('notifications_log')->where(['user_id' => $u->id, 'kind' => 'test.kind'])->value('sent_at'));
    }

    public function test_rate_limit_429_pauses_queue_and_retries_after(): void
    {
        app(Settings::class)->set(['telegram.outbox_inline_limit' => 0], null);
        $a = $this->linked(21);
        $b = $this->linked(22);
        $this->script[21] = [[429, ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]]]];
        app(Notifier::class)->sendText($a->id, 'k', 'A');
        app(Notifier::class)->sendText($b->id, 'k', 'B');

        $stats = app(Outbox::class)->drain();
        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['retried']);
        $this->assertSame(0, $this->sentTo(22), "Pauza vaqtida boshqa xabar ham yuborilmaydi (umumiy chegara)");
        $row = DB::table('telegram_outbox')->where('chat_id', 21)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->attempts, '429 urinish hisoblanmaydi');

        Carbon::setTestNow(now()->addSeconds(8));
        $this->travel(8)->seconds();
        $stats = app(Outbox::class)->drain();
        $this->assertSame(2, $stats['sent']);
        $this->assertSame(0, DB::table('telegram_outbox')->where('status', 'pending')->count());
    }

    public function test_blocked_chat_is_marked_and_skipped_afterwards(): void
    {
        $u = $this->linked(31);
        $this->script[31] = [[403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user']]];
        app(Notifier::class)->sendText($u->id, 'k1', 'A');
        $this->assertNotNull(DB::table('telegram_links')->where('user_id', $u->id)->value('blocked_at'));
        $this->assertSame('blocked', DB::table('notifications_log')->where('kind', 'k1')->value('error'));

        // Keyingi xabarlar navbatga ham qo'yilmaydi.
        app(Notifier::class)->sendText($u->id, 'k2', 'B');
        $this->assertSame(1, $this->sentTo(31));
        $this->assertSame('blocked', DB::table('notifications_log')->where('kind', 'k2')->value('error'));
        $this->assertSame(1, DB::table('telegram_outbox')->count());

        // Admin ro'yxatda ko'radi.
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->getJson('/v1/users?telegram=blocked')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $u->id)->assertJsonPath('data.0.telegram.blocked', true);
        $this->getJson('/v1/users?telegram=none')->assertOk()->assertJsonPath('meta.total', 1); // admin o'zi

        // Foydalanuvchi botga qayta yozsa — blok olinadi.
        $this->postJson('/v1/telegram/webhook', ['update_id' => 1, 'message' => ['message_id' => 1, 'text' => '/menu',
            'chat' => ['id' => 31, 'type' => 'private'], 'from' => ['id' => 31, 'first_name' => 'X']]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $this->assertNull(DB::table('telegram_links')->where('user_id', $u->id)->value('blocked_at'));
    }

    public function test_transient_errors_retry_with_backoff_then_fail(): void
    {
        app(Settings::class)->set(['telegram.outbox_inline_limit' => 0, 'telegram.outbox_max_attempts' => 2], null);
        $u = $this->linked(41);
        $this->script[41] = [
            [502, ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway']],
            [502, ['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway']],
        ];
        app(Notifier::class)->sendText($u->id, 'k', 'A');
        app(Outbox::class)->drain();
        $row = DB::table('telegram_outbox')->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertTrue(Carbon::parse($row->available_at)->greaterThan(now()));

        // Kutish vaqti o'tmaguncha yuborilmaydi.
        app(Outbox::class)->drain();
        $this->assertSame(1, $this->sentTo(41));

        $this->travel(31)->seconds();
        app(Outbox::class)->drain();
        $this->assertSame('failed', DB::table('telegram_outbox')->value('status'));
        $this->assertStringContainsString('Bad Gateway', (string) DB::table('notifications_log')->value('error'));
    }

    public function test_reminder_dedup_counts_only_delivered_or_final(): void
    {
        $u = $this->linked(51);
        $this->assertFalse(\App\Console\Commands\AutomationCommands::alreadySent($u->id, 'lesson.reminder:1'));
        DB::table('notifications_log')->insert(['user_id' => $u->id, 'channel' => 'telegram', 'kind' => 'lesson.reminder:1', 'payload' => '{}',
            'error' => 'Telegram sendMessage: Bad Gateway', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse(\App\Console\Commands\AutomationCommands::alreadySent($u->id, 'lesson.reminder:1'), 'vaqtinchalik xato — qayta uriniladi');
        DB::table('telegram_outbox')->insert(['user_id' => $u->id, 'chat_id' => 51, 'kind' => 'lesson.reminder:1', 'text' => 'x', 'status' => 'pending',
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(\App\Console\Commands\AutomationCommands::alreadySent($u->id, 'lesson.reminder:1'), 'navbatda');
    }

    public function test_outbox_disabled_sends_immediately_like_before(): void
    {
        app(Settings::class)->set(['telegram.outbox_enabled' => false], null);
        $u = $this->linked(61);
        app(Notifier::class)->sendText($u->id, 'k', 'A');
        $this->assertSame(1, $this->sentTo(61));
        $this->assertSame(0, DB::table('telegram_outbox')->count());
        $this->assertNotNull(DB::table('notifications_log')->value('sent_at'));
    }

    public function test_drain_command_paces_large_batch_and_reports_to_admin(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        DB::table('telegram_links')->insert(['user_id' => $admin->id, 'telegram_id' => 7000, 'chat_id' => 7000, 'linked_at' => now()]);
        $recipients = collect(range(1, 60))->map(fn ($i) => (object) ['user_id' => $this->linked(8000 + $i)->id, 'chat_id' => 8000 + $i]);
        $this->script[8005] = [[403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: user is deactivated']]];

        $batch = app(Outbox::class)->createBatch('broadcast', $recipients, 'E\'lon', 7000, $admin->id, ['audience' => 'all']);
        $this->assertSame(60, $batch['total']);
        // Bir martalik cheklangan ishga tushish (uzilish simulyatsiyasi) — keyingi cron qolganidan davom ettiradi.
        $this->artisan('tisu:outbox-drain', ['--limit' => 25])->expectsOutputToContain('navbatda: 35')->assertSuccessful();
        $this->assertSame(0, $this->sentTo(7000), 'tugamaguncha hisobot yo\'q');
        $this->artisan('tisu:outbox-drain')->expectsOutputToContain('navbatda: 0')->assertSuccessful();

        $this->assertSame(1, $this->sentTo(7000));
        Http::assertSent(fn (HttpRequest $r) => (int) ($r['chat_id'] ?? 0) === 7000 && str_contains((string) $r['text'], 'Yuborildi: 59'));
        $this->assertNotNull(DB::table('telegram_batches')->value('finished_at'));
        $audit = json_decode((string) DB::table('audit_logs')->where('action', 'telegram.broadcast')->value('data'), true);
        $this->assertSame(['sent' => 59, 'blocked' => 1, 'total' => 60], array_intersect_key($audit, ['sent' => 1, 'blocked' => 1, 'total' => 1]));
        // Ikkinchi marta hisobot yuborilmaydi.
        app(Outbox::class)->finishBatches();
        $this->assertSame(1, $this->sentTo(7000));
    }

    public function test_only_one_drainer_at_a_time(): void
    {
        $lock = Cache::lock('tg.outbox.lock', 60);
        $this->assertTrue($lock->get());
        $this->assertTrue(app(Outbox::class)->drain()['locked'] ?? false);
        $lock->release();
    }
}
