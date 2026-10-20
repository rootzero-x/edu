<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use App\Support\Phone;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** #3 yagona telefon normalizatsiyasi va takrorlar; #5 bot orqali ariza (admin tasdig'i). */
class PhoneAndRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    private const ADMIN_CHAT = 5000;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '1:T', 'services.telegram.webhook_secret' => self::SECRET]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->admin = User::factory()->role(Role::Admin)->create();
        DB::table('telegram_links')->insert(['user_id' => $this->admin->id, 'telegram_id' => self::ADMIN_CHAT, 'chat_id' => self::ADMIN_CHAT, 'linked_at' => now()]);
        app(Settings::class)->set(['telegram.admin_chat_ids' => [self::ADMIN_CHAT]], null);
    }

    private function webhook(array $update): void
    {
        $this->postJson('/v1/telegram/webhook', ['update_id' => 1] + $update, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function contact(int $tg, string $phone, string $name = 'Yangi'): void
    {
        $this->webhook(['message' => ['message_id' => 1, 'chat' => ['id' => $tg, 'type' => 'private'],
            'from' => ['id' => $tg, 'first_name' => $name, 'username' => 'u'.$tg],
            'contact' => ['phone_number' => $phone, 'user_id' => $tg, 'first_name' => $name]]]);
    }

    private function press(int $tg, string $data): void
    {
        $this->webhook(['callback_query' => ['id' => 'cb'.$tg, 'data' => $data, 'from' => ['id' => $tg, 'first_name' => 'X'],
            'message' => ['message_id' => 77, 'chat' => ['id' => $tg, 'type' => 'private']]]]);
    }

    private function lastTo(int $chat): HttpRequest
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (HttpRequest $r) => (int) ($r['chat_id'] ?? 0) === $chat && (str_contains($r->url(), '/sendMessage') || str_contains($r->url(), '/editMessageText')))
            ->last();
    }

    // ── #3 Telefon ───────────────────────────────────────────

    public function test_normalize_maps_all_local_formats(): void
    {
        foreach (['90 123 45 67', '901234567', '8 90 123 45 67', '998901234567', '+998 (90) 123-45-67', '00998901234567', '+998901234567'] as $raw) {
            $this->assertSame('+998901234567', Phone::normalize($raw), $raw);
        }
        foreach (['', '12345', '+90 532 123 45 67', '99890123456', 'abc'] as $bad) {
            $this->assertNull(Phone::normalize($bad), $bad);
        }
        $this->assertSame('+905321234567', Phone::normalize('+90 532 123 45 67', true));
        $this->assertNull(Phone::normalize('5321234567', true), 'xalqaro raqam + bilan yozilishi kerak');
        $this->assertSame('901234', Phone::searchDigits('90 12-34'));
        $this->assertNull(Phone::searchDigits('Ali 90'));
    }

    public function test_login_and_search_accept_any_format(): void
    {
        User::factory()->create(['phone' => '+998901234567', 'password' => 'secret-password', 'first_name' => 'Ali']);
        $this->postJson('/v1/auth/login', ['phone' => '90 123 45 67', 'password' => 'secret-password'])->assertOk();
        $this->postJson('/v1/auth/login', ['phone' => '90 123 45 67', 'password' => 'wrong-password'])->assertUnprocessable();
        $this->assertSame(['auth.login', 'auth.login_failed'], DB::table('audit_logs')->orderBy('id')->pluck('action')->all());
        // Eski bot saqlagan yozuv ("+" + 9 raqam) ham har qanday formatda kiradi.
        User::factory()->create(['phone' => '+931112233', 'password' => 'secret-password']);
        $this->postJson('/v1/auth/login', ['phone' => '93 111 22 33', 'password' => 'secret-password'])->assertOk();

        Sanctum::actingAs($this->admin);
        $this->getJson('/v1/users?q='.urlencode('90 123'))->assertOk()->assertJsonPath('meta.total', 1);
        // Mavjud raqamni boshqa formatda kiritish takror yaratmaydi.
        $this->postJson('/v1/users', ['phone' => '8 90 123 45 67', 'first_name' => 'Takror', 'role' => 'STUDENT'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_foreign_phones_only_when_allowed(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/v1/users', ['phone' => '+44 7700 900123', 'first_name' => 'Jon', 'role' => 'STUDENT'])->assertUnprocessable();
        app(Settings::class)->set(['auth.allow_foreign_phones' => true], null);
        $this->postJson('/v1/users', ['phone' => '+44 7700 900123', 'first_name' => 'Jon', 'role' => 'STUDENT'])
            ->assertCreated()->assertJsonPath('user.phone', '+447700900123');
    }

    public function test_duplicates_report_and_safe_merge(): void
    {
        $main = User::factory()->create(['phone' => '+998901112233', 'first_name' => 'Ali', 'last_name' => 'Valiyev']);
        \App\Models\Course::create(['title' => 'Tarix', 'created_by' => $main->id]); // tarix: muallif
        // Eski formatda yozilgan takror (bot normalizatsiyasidan oldin) va boshqa raqamli bir xil ism.
        $dupe = User::factory()->create(['phone' => '+901112233', 'first_name' => 'Ali', 'last_name' => 'Valiyev']);
        DB::table('telegram_links')->insert(['user_id' => $dupe->id, 'telegram_id' => 6001, 'chat_id' => 6001, 'linked_at' => now()]);

        Sanctum::actingAs($this->admin);
        $r = $this->getJson('/v1/users/duplicates')->assertOk();
        $this->assertSame('+998901112233', $r->json('phone_groups.0.phone'));
        $this->assertSame([$main->id, $dupe->id], array_column($r->json('phone_groups.0.users'), 'id'));
        $this->assertTrue($r->json('phone_groups.0.users.0.history'));
        $this->assertFalse($r->json('phone_groups.0.users.1.history'));

        // Tarixli akkauntni tarixsiziga qo'shib bo'lmaydi.
        $this->postJson("/v1/users/{$main->id}/merge", ['into_id' => $dupe->id])->assertStatus(409);
        $this->postJson("/v1/users/{$dupe->id}/merge", ['into_id' => $main->id])->assertOk();
        $this->assertSame($main->id, (int) DB::table('telegram_links')->where('telegram_id', 6001)->value('user_id'));
        $dupe->refresh();
        $this->assertFalse($dupe->active);
        $this->assertStringStartsWith('merged:', $dupe->phone);
        $this->assertSame([], $this->getJson('/v1/users/duplicates')->json('phone_groups'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'user.merge')->exists());
    }

    public function test_duplicates_command_fixes_non_canonical_phones(): void
    {
        $a = User::factory()->create(['phone' => '+901234500']); // eski bot: "+" + 9 raqam
        $b = User::factory()->create(['phone' => '998907770000']);
        $this->artisan('tisu:users-duplicates')->expectsOutputToContain("To'g'rilanadi (--fix): 2")->assertSuccessful();
        $this->assertSame('+901234500', $a->fresh()->phone);
        $this->artisan('tisu:users-duplicates --fix')->assertSuccessful();
        $this->assertSame('+998901234500', $a->fresh()->phone);
        $this->assertSame('+998907770000', $b->fresh()->phone);
    }

    // ── #5 Ariza ─────────────────────────────────────────────

    public function test_bot_self_registration_creates_pending_applicant(): void
    {
        $this->contact(700, '90 555 00 11', 'Yangi');
        $user = User::where('phone', '+998905550011')->firstOrFail();
        $this->assertSame(Role::Student, $user->role);
        $this->assertFalse($user->active);
        $this->assertTrue($user->isPending());
        $this->assertStringContainsString('Arizangiz qabul qilindi', (string) $this->lastTo(700)['text']);
        // Adminga ariza: tasdiqlash / rad etish tugmalari.
        $adminMsg = $this->lastTo(self::ADMIN_CHAT);
        $this->assertStringContainsString('Yangi ariza', (string) $adminMsg['text']);
        $this->assertSame("adm:ap:{$user->id}", $adminMsg['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        // Tasdiqlanmaguncha bot "ko'rib chiqilmoqda" deydi.
        $this->webhook(['message' => ['message_id' => 2, 'text' => '/start', 'chat' => ['id' => 700, 'type' => 'private'], 'from' => ['id' => 700, 'first_name' => 'Yangi']]]);
        $this->assertStringContainsString("ko'rib chiqilmoqda", (string) $this->lastTo(700)['text']);

        // Admin bot orqali tasdiqlaydi → faol, foydalanuvchiga xabar.
        $this->press(self::ADMIN_CHAT, "adm:ap:{$user->id}");
        $user->refresh();
        $this->assertTrue($user->active);
        $this->assertFalse($user->isPending());
        $this->assertStringContainsString('tasdiqlandi', (string) $this->lastTo(700)['text']);
        $this->assertStringContainsString('faollashtirildi', (string) $this->lastTo(self::ADMIN_CHAT)['text']);
        $this->assertSame($this->admin->id, (int) DB::table('audit_logs')->where('action', 'user.approve')->value('actor_id'));
        // Ikkinchi bosish — "allaqachon ko'rib chiqilgan".
        $this->press(self::ADMIN_CHAT, "adm:rj:{$user->id}");
        $this->assertStringContainsString('allaqachon', (string) $this->lastTo(self::ADMIN_CHAT)['text']);
    }

    public function test_web_approve_and_reject_applicants(): void
    {
        $this->contact(701, '+998905550012');
        $this->contact(702, '+998905550013');
        $a = User::where('phone', '+998905550012')->firstOrFail();
        $b = User::where('phone', '+998905550013')->firstOrFail();
        $this->assertSame(Role::Student, $a->role);

        Sanctum::actingAs($this->admin);
        $this->getJson('/v1/users?pending=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.pending', 2)
            ->assertJsonPath('data.0.pending', true);
        $this->postJson("/v1/users/{$a->id}/approve", ['role' => 'ADMIN'])->assertUnprocessable();
        $this->postJson("/v1/users/{$a->id}/approve", ['role' => 'STUDENT'])->assertOk()->assertJsonPath('user.active', true)->assertJsonPath('user.pending', false);
        $this->postJson("/v1/users/{$a->id}/approve", ['role' => 'STUDENT'])->assertStatus(409);
        $this->postJson("/v1/users/{$b->id}/reject")->assertOk()->assertJsonPath('user.active', false);
        $this->assertStringContainsString('rad etildi', (string) $this->lastTo(702)['text']);
        $this->getJson('/v1/users?pending=1')->assertJsonPath('meta.total', 0);
        // Rad etilgan bot orqali "faol emas" deb javob oladi.
        $this->contact(702, '+998905550013');
        $this->assertStringContainsString('faol emas', (string) $this->lastTo(702)['text']);
    }

    public function test_foreign_contact_cannot_self_register_but_existing_legacy_user_links(): void
    {
        $this->contact(703, '+90 532 111 22 33');
        $this->assertStringContainsString('+998', (string) $this->lastTo(703)['text']);
        $this->assertSame(1, User::count());

        // Eski bot saqlagan xorijiy/noto'g'ri raqamli akkaunt — bog'lanadi.
        $legacy = User::factory()->create(['phone' => '+905321112234']);
        $this->contact(704, '+90 532 111 22 34');
        $this->assertSame($legacy->id, (int) DB::table('telegram_links')->where('telegram_id', 704)->value('user_id'));
    }

    public function test_old_behaviour_without_approval_setting(): void
    {
        app(Settings::class)->set(['telegram.self_registration_approval' => false], null);
        $this->contact(705, '93 555 00 14');
        $user = User::where('phone', '+998935550014')->firstOrFail();
        $this->assertTrue($user->active);
        $this->assertFalse($user->isPending());
        $this->assertSame(Role::Student, $user->role);
    }
}
