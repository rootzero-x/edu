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

    public function test_webhook_rejects_wrong_secret(): void
    {
        $this->postJson('/v1/telegram/webhook', ['message' => []], ['X-Telegram-Bot-Api-Secret-Token' => 'nope'])->assertForbidden();
    }

    public function test_full_web_login_with_self_registration(): void
    {
        $start = $this->postJson('/v1/auth/telegram/start')->assertOk()->json();
        $this->assertStringStartsWith('https://t.me/tisu_test_bot?start=login_', $start['bot_url']);
        $poll = fn () => $this->postJson('/v1/auth/telegram/poll', ['code' => $start['code'], 'poll_secret' => $start['poll_secret']]);

        $poll()->assertOk()->assertJsonPath('status', 'pending');
        $this->update(['text' => '/start login_'.$start['code']]);
        $poll()->assertJsonPath('status', 'pending');

        $this->update(['contact' => ['phone_number' => '998901234567', 'user_id' => 555, 'first_name' => 'Ali']]);

        $res = $poll()->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('user.phone', '+998901234567')
            ->assertJsonPath('user.role', 'STUDENT');
        $this->withToken($res->json('token'))->getJson('/v1/auth/me')->assertOk();
        // Token faqat bir marta beriladi.
        $poll()->assertJsonPath('status', 'expired');
        // Begona brauzer poll_secret'siz ololmaydi.
        $this->postJson('/v1/auth/telegram/poll', ['code' => $start['code'], 'poll_secret' => 'x'])->assertNotFound();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], "ro'yxatdan o'tdingiz"));
    }

    public function test_linked_user_logs_in_with_one_tap(): void
    {
        $user = User::factory()->create(['phone' => '+998907777777']);
        $this->update(['contact' => ['phone_number' => '+998907777777', 'user_id' => 555]]);
        $this->assertSame($user->id, (int) DB::table('telegram_links')->where('telegram_id', 555)->value('user_id'));

        $start = $this->postJson('/v1/auth/telegram/start')->json();
        $this->update(['text' => '/start login_'.$start['code']]);
        $this->postJson('/v1/auth/telegram/poll', ['code' => $start['code'], 'poll_secret' => $start['poll_secret']])
            ->assertJsonPath('status', 'ok')->assertJsonPath('user.id', $user->id);
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
}
