<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sprint 3 #15: qo'ng'iroqcha (o'qilgan/o'qilmagan), yangiliklar sinxroni va yetkazish hisoboti. */
class NotificationCentreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $unlinked;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create(['first_name' => 'Aziz']);
        $this->unlinked = User::factory()->create(['first_name' => 'Bobur']);
        foreach ([$this->student, $this->teacher, $this->admin] as $i => $u) {
            DB::table('telegram_links')->insert(['user_id' => $u->id, 'telegram_id' => 500 + $i, 'chat_id' => 500 + $i, 'linked_at' => now()]);
        }
    }

    private function log(int $userId, string $kind, ?string $error = null, string $text = 'Matn'): void
    {
        DB::table('notifications_log')->insert([
            'user_id' => $userId, 'channel' => 'telegram', 'kind' => $kind, 'payload' => json_encode(['text' => $text]),
            'sent_at' => $error ? null : now(), 'error' => $error, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_bell_lists_own_notifications_and_marks_them_read(): void
    {
        app(Notifier::class)->sendTemplate($this->student->id, 'homework.new:7', 'telegram.text.homework_new',
            ['name' => 'Aziz', 'group' => 'ENG', 'title' => 'Mashq', 'due' => '—', 'url' => '']);
        $this->log($this->student->id, 'attendance.ABSENT');
        $this->log($this->student->id, 'lesson.reminder:3', 'skipped'); // --skip bilan o'tkazilgan — ko'rinmaydi
        $this->log($this->student->id, 'login.confirm');                // ro'yxatda yo'q tur — ko'rinmaydi
        $this->log($this->unlinked->id, 'homework.new:7', 'telegram_not_linked');

        Sanctum::actingAs($this->student);
        $u = $this->getJson('/v1/notifications/unread')->assertOk()->assertJsonPath('unread', 2);
        $this->assertSame('/dashboard/homework/7', collect($u->json('latest'))->firstWhere('type', 'homework')['url']);
        $this->assertSame('Uyga vazifa', collect($u->json('latest'))->firstWhere('type', 'homework')['label']);
        $list = $this->getJson('/v1/notifications')->assertOk()->assertJsonCount(2, 'data');
        $first = $list->json('data.1.id');
        $this->postJson('/v1/notifications/read', ['ids' => [$first]])->assertOk()->assertJsonPath('unread', 1);
        $this->getJson('/v1/notifications?unread=1')->assertJsonCount(1, 'data');
        $this->postJson('/v1/notifications/read', ['all' => true])->assertOk()->assertJsonPath('unread', 0);
        $this->postJson('/v1/notifications/read', [])->assertUnprocessable();

        // Telegram ulanmagan foydalanuvchi ham xabarni panelda ko'radi; boshqaning xabarini o'qilgan qila olmaydi.
        Sanctum::actingAs($this->unlinked);
        $this->getJson('/v1/notifications/unread')->assertJsonPath('unread', 1)->assertJsonPath('latest.0.delivery', 'not_linked');
        $this->postJson('/v1/notifications/read', ['ids' => [$first]])->assertJsonPath('updated', 0);
    }

    public function test_pushed_or_bell_opened_news_counts_as_read(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/v1/announcements', ['title' => 'Bayram', 'body' => 'Dam olish', 'audience' => 'ALL', 'notify_telegram' => true, 'publish' => true])
            ->assertCreated()->json('announcement.id');
        $this->assertDatabaseHas('notifications_log', ['kind' => "announcement:$id", 'user_id' => $this->student->id]);
        // Telegram'ga yuborilganlar uchun — o'qilgan; ulanmagan o'quvchi uchun — o'qilmagan.
        $this->assertDatabaseHas('announcement_reads', ['announcement_id' => $id, 'user_id' => $this->student->id]);
        Sanctum::actingAs($this->unlinked);
        $this->getJson('/v1/notifications/unread')->assertJsonPath('news_unread', 1);

        // Qo'ng'iroqchada e'lon xabari o'qilsa — yangilik ham o'qiladi.
        app(Settings::class)->set(['notifications.mark_pushed_news_read' => false], null);
        $this->log($this->unlinked->id, "announcement:$id", 'telegram_not_linked');
        $this->postJson('/v1/notifications/read', ['all' => true])->assertOk()->assertJsonPath('news_unread', 0);
    }

    public function test_admin_delivery_report_and_unreachable_list(): void
    {
        $this->log($this->student->id, 'homework.new:1');
        $this->log($this->student->id, 'homework.due:1', 'Forbidden: bot was blocked by the user');
        $this->log($this->unlinked->id, 'homework.new:1', 'telegram_not_linked');
        $this->log($this->teacher->id, 'attendance.unmarked:5', 'Bad Request: message is too long');
        $this->log($this->teacher->id, 'lesson.reminder:5', 'skipped');

        Sanctum::actingAs($this->teacher);
        $this->getJson('/v1/notifications/report')->assertForbidden();
        $this->getJson('/v1/notifications/unreachable')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $r = $this->getJson('/v1/notifications/report')->assertOk();
        $this->assertSame(5, $r->json('total.total'));
        $hw = collect($r->json('by_kind'))->firstWhere('kind', 'homework');
        $this->assertSame(['sent' => 1, 'failed' => 0, 'not_linked' => 1, 'blocked' => 1, 'skipped' => 0, 'not_configured' => 0], $hw['counts']);
        $this->assertEquals(33.3, $hw['delivered_percent']);
        $this->assertSame(1, collect($r->json('by_kind'))->firstWhere('kind', 'attendance')['counts']['failed']);
        $students = collect($r->json('reach'))->firstWhere('role', 'STUDENT');
        // Ulanmagan (Bobur) va botni bloklagan (Aziz) — ikkalasiga ham yetib bo'lmaydi.
        $this->assertSame(['total' => 2, 'reachable' => 0], ['total' => $students['total'], 'reachable' => $students['reachable']]);
        $this->assertNull(collect($r->json('reach'))->firstWhere('role', 'PARENT'));

        $u = $this->getJson('/v1/notifications/unreachable?role=STUDENT')->assertOk();
        $this->assertEqualsCanonicalizing(['blocked', 'not_linked'], array_column($u->json('data'), 'reason'));
        $this->getJson('/v1/notifications/unreachable?reason=not_linked')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', $this->unlinked->first_name.' '.$this->unlinked->last_name);

        $csv = $this->get('/v1/notifications/unreachable.csv')->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('Telegram ulanmagan', $body);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.unreachable_csv']);
    }
}
