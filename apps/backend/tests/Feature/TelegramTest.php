<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings;
use App\Telegram\InitData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:TEST-token-for-unit-tests';

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => self::TOKEN, 'services.telegram.webhook_secret' => self::SECRET]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        app(Settings::class)->set(['telegram.bot_username' => 'tisu_test_bot'], null);
    }

    private function update(array $message): void
    {
        $message += ['chat' => ['id' => 555, 'type' => 'private'], 'from' => ['id' => 555, 'first_name' => 'Ali']];
        $this->postJson('/v1/telegram/webhook', ['update_id' => 1, 'message' => $message],
            ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function tap(string $data, int $fromId = 555): void
    {
        $this->postJson('/v1/telegram/webhook', ['update_id' => 2, 'callback_query' => [
            'id' => 'cb1', 'data' => $data, 'from' => ['id' => $fromId, 'first_name' => 'Ali'],
            'message' => ['message_id' => 10, 'chat' => ['id' => $fromId, 'type' => 'private']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $this->postJson('/v1/telegram/webhook', ['message' => []], ['X-Telegram-Bot-Api-Secret-Token' => 'nope'])->assertForbidden();
    }

    public function test_full_web_login_with_self_registration(): void
    {
        // Tasdiqsiz rejim (eski xatti-harakat sozlama bilan saqlanadi).
        app(\App\Support\Settings::class)->set(['telegram.self_registration_approval' => false], null);
        $start = $this->postJson('/v1/auth/telegram/start')->assertOk()->json();
        $this->assertStringStartsWith('https://t.me/tisu_test_bot?start=login_', $start['bot_url']);
        $poll = fn () => $this->postJson('/v1/auth/telegram/poll', ['code' => $start['code'], 'poll_secret' => $start['poll_secret']]);

        $poll()->assertOk()->assertJsonPath('status', 'pending');
        $this->update(['text' => '/start login_'.$start['code']]);
        $poll()->assertJsonPath('status', 'pending');

        $this->update(['contact' => ['phone_number' => '998901234567', 'user_id' => 555, 'first_name' => 'Ali']]);
        // Kontakt yuborilgani yetarli emas — kirish alohida tasdiqlanadi.
        $poll()->assertJsonPath('status', 'pending');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && ($r['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? '') === 'lg:y:'.$start['code']);
        $this->tap('lg:y:'.$start['code']);

        $res = $poll()->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('user.phone', '+998901234567')
            ->assertJsonPath('user.role', 'STUDENT');
        $this->withToken($res->json('token'))->getJson('/v1/auth/me')->assertOk();
        // Token faqat bir marta beriladi.
        $poll()->assertJsonPath('status', 'expired');
        // Begona brauzer poll_secret'siz ololmaydi.
        $this->postJson('/v1/auth/telegram/poll', ['code' => $start['code'], 'poll_secret' => 'x'])->assertNotFound();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], "ro'yxatdan o'tdingiz"));
    }

    public function test_linked_user_confirms_login_with_button(): void
    {
        $user = User::factory()->create(['phone' => '+998907777777']);
        $this->update(['contact' => ['phone_number' => '+998907777777', 'user_id' => 555]]);
        $this->assertSame($user->id, (int) DB::table('telegram_links')->where('telegram_id', 555)->value('user_id'));

        $start = $this->postJson('/v1/auth/telegram/start')->json();
        $poll = fn () => $this->postJson('/v1/auth/telegram/poll', ['code' => $start['code'], 'poll_secret' => $start['poll_secret']]);
        $this->update(['text' => '/start login_'.$start['code']]);
        $poll()->assertJsonPath('status', 'pending');
        $this->tap('lg:y:'.$start['code']);
        $poll()->assertJsonPath('status', 'ok')->assertJsonPath('user.id', $user->id);
    }

    /** Hujumchi o'z kodi bilan havola yuboradi — qurbon bossa ham, tasdiqlamasa token berilmaydi; rad etsa kod yopiladi. */
    public function test_forwarded_login_link_cannot_take_over_account(): void
    {
        User::factory()->create(['phone' => '+998907777777']);
        $this->update(['contact' => ['phone_number' => '+998907777777', 'user_id' => 555]]);
        $attacker = $this->postJson('/v1/auth/telegram/start')->json();
        $poll = fn () => $this->postJson('/v1/auth/telegram/poll', ['code' => $attacker['code'], 'poll_secret' => $attacker['poll_secret']]);

        $this->update(['text' => '/start login_'.$attacker['code']]);
        $poll()->assertJsonPath('status', 'pending');
        // Boshqa telegram akkaunt tasdiqlay olmaydi.
        $this->tap('lg:y:'.$attacker['code'], 999);
        $poll()->assertJsonPath('status', 'pending');
        $this->tap('lg:n:'.$attacker['code']);
        $poll()->assertJsonPath('status', 'expired');
        $this->tap('lg:y:'.$attacker['code']);
        $poll()->assertJsonPath('status', 'expired');
    }

    public function test_foreign_contact_and_closed_registration(): void
    {
        $this->update(['contact' => ['phone_number' => '998901111111', 'user_id' => 999]]);
        $this->assertSame(0, User::count());

        app(Settings::class)->set(['telegram.self_registration' => false], null);
        $this->update(['contact' => ['phone_number' => '998901111111', 'user_id' => 555]]);
        $this->assertSame(0, User::count());
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'administratorga'));
    }

    public function test_bot_texts_come_from_settings(): void
    {
        app(Settings::class)->set(['telegram.text.welcome' => 'Salom, {site}!', 'site.name' => 'Akademiya'], null);
        $this->update(['text' => '/start']);
        Http::assertSent(fn ($r) => ($r['text'] ?? null) === 'Salom, Akademiya!');
    }

    public function test_miniapp_init_data(): void
    {
        $user = User::factory()->create(['phone' => '+998905555555']);
        $this->update(['contact' => ['phone_number' => '998905555555', 'user_id' => 555]]);

        $fields = ['auth_date' => (string) time(), 'query_id' => 'AAA', 'user' => json_encode(['id' => 555, 'first_name' => 'Ali'])];
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));
        $hash = hash_hmac('sha256', $check, hash_hmac('sha256', self::TOKEN, 'WebAppData', true));
        $initData = http_build_query($fields + ['hash' => $hash]);

        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $initData])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => str_replace($hash, str_repeat('0', 64), $initData)])
            ->assertUnauthorized();
        $this->assertNull(InitData::verify($initData, self::TOKEN, 3600, time() + 7200));
    }

    public function test_start_for_linked_user_greets_without_relinking_message(): void
    {
        $user = User::factory()->create(['phone' => '+998906666666', 'first_name' => 'Khayrullayevich']);
        $this->update(['contact' => ['phone_number' => '998906666666', 'user_id' => 555]]);
        Http::assertSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), "Telegram akkauntingiz bog'landi"));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telegram.link')->count());

        // Har bir /start va kontaktni qayta yuborish — salomlashish + menyu, "bog'landi" emas.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->update(['text' => '/start']);
        $this->update(['text' => '/start']);
        $this->update(['contact' => ['phone_number' => '998906666666', 'user_id' => 555]]);
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), "bog'landi"));
        Http::assertSent(fn ($r) => str_starts_with((string) ($r['text'] ?? ''), 'Assalomu alaykum, Khayrullayevich!')
            && isset($r['reply_markup']['keyboard']));
        Http::assertSentCount(3);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telegram.link')->count());
        $this->assertSame($user->id, (int) DB::table('telegram_links')->where('telegram_id', 555)->value('user_id'));
    }

    public function test_unlinked_user_text_gets_short_phone_prompt(): void
    {
        $this->update(['text' => 'salom']);
        Http::assertSent(fn ($r) => str_starts_with((string) ($r['text'] ?? ''), 'Davom etish uchun avval')
            && ($r['reply_markup']['keyboard'][0][0]['request_contact'] ?? false));
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'xush kelibsiz'));
    }

    public function test_miniapp_reports_pending_applicant_and_relinking_ends_old_miniapp_sessions(): void
    {
        $initFor = function (int $tgId): string {
            $fields = ['auth_date' => (string) time(), 'query_id' => 'AAA', 'user' => json_encode(['id' => $tgId, 'first_name' => 'Ali'])];
            ksort($fields);
            $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));

            return http_build_query($fields + ['hash' => hash_hmac('sha256', $check, hash_hmac('sha256', self::TOKEN, 'WebAppData', true))]);
        };
        $a = User::factory()->create(['phone' => '+998905555551']);
        $this->update(['contact' => ['phone_number' => '998905555551', 'user_id' => 555]]);
        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $initFor(555)])->assertOk();
        $this->assertSame(1, $a->tokens()->where('name', 'telegram-miniapp')->count());

        // Shu Telegram boshqa akkauntga ulandi — eski akkauntning Mini App tokeni o'chadi.
        User::factory()->create(['phone' => '+998905555552']);
        $this->update(['contact' => ['phone_number' => '998905555552', 'user_id' => 555]]);
        $this->assertSame(0, $a->tokens()->where('name', 'telegram-miniapp')->count());

        // Tasdiq kutayotgan ariza egasi — "pending", "bloklangan" emas.
        $p = User::factory()->create(['phone' => '+998905555553', 'active' => false, 'pending_at' => now()]);
        \Illuminate\Support\Facades\DB::table('telegram_links')->insert(['user_id' => $p->id, 'telegram_id' => 557, 'chat_id' => 557, 'linked_at' => now()]);
        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $initFor(557)])->assertForbidden()->assertJsonPath('status', 'pending');
    }

    public function test_long_messages_are_fitted_to_telegram_limit(): void
    {
        $text = implode("\n", array_fill(0, 400, str_repeat('x', 20)));
        $fit = \App\Telegram\TelegramClient::fit($text);
        $this->assertLessThanOrEqual(4096, mb_strlen($fit));
        $this->assertStringEndsWith("\n…", $fit);
        $this->assertSame('qisqa', \App\Telegram\TelegramClient::fit('qisqa'));
    }
}
