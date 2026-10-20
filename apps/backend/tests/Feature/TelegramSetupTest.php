<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** tisu:setting (CLI) va tisu:telegram-setup (profilni Bot API'ga qo'llash). */
class TelegramSetupTest extends TestCase
{
    use RefreshDatabase;

    private function sent(string $method): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn ($r) => str_ends_with($r->url(), '/'.$method))->values()->all();
    }

    public function test_setting_command_sets_value_with_validation(): void
    {
        $this->artisan('tisu:setting', ['key' => 'telegram.admin_chat_ids', 'value' => '[6817322973]'])->assertExitCode(0);
        $this->assertSame([6817322973], app(Settings::class)->get('telegram.admin_chat_ids'));

        // Settings::set validatsiyasi: ro'yxat faqat butun sonlar.
        $this->artisan('tisu:setting', ['key' => 'telegram.admin_chat_ids', 'value' => '["abc"]'])->assertExitCode(1);
        $this->artisan('tisu:setting', ['key' => 'telegram.admin_chat_ids', 'value' => '6817322973'])->assertExitCode(1);
        $this->artisan('tisu:setting', ['key' => 'telegram.list_limit', 'value' => '1000'])->assertExitCode(1);
        $this->artisan('tisu:setting', ['key' => 'telegram.admin_chat_ids', 'value' => '[1,'])->assertExitCode(1);
        $this->artisan('tisu:setting', ['key' => 'no.such.key', 'value' => '1'])->assertExitCode(1);
        $this->assertSame([6817322973], app(Settings::class)->get('telegram.admin_chat_ids'));

        $this->artisan('tisu:setting', ['key' => 'telegram.text.menu', 'value' => '"Menyu"'])->assertExitCode(0);
        $this->assertSame('Menyu', app(Settings::class)->get('telegram.text.menu'));
        $this->artisan('tisu:setting', ['key' => 'telegram.admin_chat_ids'])
            ->expectsOutputToContain('6817322973')->assertExitCode(0);

        $this->artisan('tisu:setting', ['key' => 'telegram.admin_chat_ids', '--reset' => true])->assertExitCode(0);
        $this->assertSame([], app(Settings::class)->get('telegram.admin_chat_ids'));
    }

    public function test_telegram_setup_applies_profile_from_settings(): void
    {
        config(['services.telegram.bot_token' => '1:T', 'services.telegram.webhook_secret' => str_repeat('s', 40), 'app.url' => 'https://api.example.test']);
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'edu_tisu_bot']]),
            'api.telegram.org/*/getMyName' => Http::response(['ok' => true, 'result' => ['name' => 'TISU Edu']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
        app(Settings::class)->set([
            'telegram.admin_chat_ids' => [6817322973],
            'telegram.profile.short_description' => ['ru' => 'Короткое описание'],
        ], null);

        $this->artisan('tisu:telegram-setup')->assertExitCode(0);

        $this->assertSame('edu_tisu_bot', app(Settings::class)->get('telegram.bot_username'));
        $webhook = $this->sent('setWebhook')[0];
        $this->assertSame('https://api.example.test/v1/telegram/webhook', $webhook['url']);
        $this->assertContains('callback_query', $webhook['allowed_updates']);

        // Nom o'zgarmagan — qayta yuborilmaydi (Telegram limitlari).
        $this->assertSame([], $this->sent('setMyName'));
        $short = collect($this->sent('setMyShortDescription'))->firstWhere('language_code', 'ru');
        $this->assertSame('Короткое описание', $short['short_description']);
        $this->assertCount(3, $this->sent('setMyDescription'));

        $commands = $this->sent('setMyCommands');
        $default = collect($commands)->first(fn ($r) => ! isset($r['language_code']) && ! isset($r['scope']));
        $this->assertContains('schedule', array_column($default['commands'], 'command'));
        $this->assertNotContains('broadcast', array_column($default['commands'], 'command'));
        $admin = collect($commands)->first(fn ($r) => ($r['scope']['chat_id'] ?? null) === 6817322973);
        $this->assertContains('broadcast', array_column($admin['commands'], 'command'));

        $menu = $this->sent('setChatMenuButton')[0]['menu_button'];
        $this->assertSame(['type' => 'web_app', 'text' => '📱 Ilova', 'web_app' => ['url' => 'https://tisu.uzstudents.uz/tg']], $menu);
        // Avatar sozlanmagan — o'zgartirilmaydi.
        $this->assertSame([], $this->sent('setMyProfilePhoto'));
    }

    public function test_telegram_setup_uploads_profile_photo_once(): void
    {
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake([
            'cdn.example.test/*' => Http::response("\xFF\xD8\xFF\xE0fake-jpeg"),
            'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'edu_tisu_bot']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
        ]);
        app(Settings::class)->set(['telegram.profile.photo_url' => 'https://cdn.example.test/avatar.jpg'], null);

        $this->artisan('tisu:telegram-setup', ['--skip-webhook' => true])->assertExitCode(0);
        $this->artisan('tisu:telegram-setup', ['--skip-webhook' => true])->assertExitCode(0);

        $uploads = $this->sent('setMyProfilePhoto');
        $this->assertCount(1, $uploads);
        $this->assertTrue($uploads[0]->isMultipart());
        $this->assertSame([], $this->sent('setWebhook'));
    }

    public function test_invalid_command_is_reported_but_other_steps_continue(): void
    {
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['username' => 'edu_tisu_bot']])]);
        app(Settings::class)->set(['telegram.profile.commands' => ['default' => [['command' => 'Bad Command!', 'description' => 'x']]]], null);

        $this->artisan('tisu:telegram-setup', ['--skip-webhook' => true])->assertExitCode(1);
        $this->assertNotEmpty($this->sent('setChatMenuButton'));
    }
}
