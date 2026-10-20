<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use App\Support\Backup\BackupVault;
use App\Support\Settings;
use App\Support\SystemStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** #1: shifrlangan zaxira, Telegram'ga (serverdan tashqariga) yuborish va tiklash. */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_CHAT = 4242;

    private string $dir;

    private bool $failDocuments = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tisu-backup-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        config(['backup.dir' => $this->dir, 'backup.key' => BackupVault::generateKey(), 'services.telegram.bot_token' => '1:T']);
        Http::fake(fn (HttpRequest $r) => $this->failDocuments && str_contains($r->url(), '/sendDocument')
            ? Http::response(['ok' => false, 'error_code' => 413, 'description' => 'Request Entity Too Large'], 413)
            : Http::response(['ok' => true, 'result' => ['message_id' => 1]]));
        $admin = User::factory()->role(Role::Admin)->create();
        DB::table('telegram_links')->insert(['user_id' => $admin->id, 'telegram_id' => self::ADMIN_CHAT, 'chat_id' => self::ADMIN_CHAT, 'linked_at' => now()]);
        app(Settings::class)->set(['telegram.admin_chat_ids' => [self::ADMIN_CHAT]], null);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && @unlink($f);
        }
        foreach (glob($this->dir.'/*', GLOB_ONLYDIR) ?: [] as $d) {
            array_map('unlink', glob($d.'/*') ?: []);
            @rmdir($d);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function dumpFixture(int $padBytes = 0): string
    {
        $sql = "-- MySQL dump\nDROP TABLE IF EXISTS `users`;\nCREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1),(2);\n"
            ."CREATE TABLE `courses` (`id` int);\nINSERT INTO `courses` VALUES (1);\n";
        if ($padBytes) {
            // Siqilmaydigan ma'lumot (bo'laklarga bo'linishini tekshirish uchun).
            $sql .= '-- '.base64_encode(random_bytes($padBytes))."\n";
        }
        $sql .= "-- Dump completed on 2026-10-21\n";
        $path = $this->dir.'/fixture.sql.gz';
        file_put_contents($path, gzencode($sql));

        return $path;
    }

    private function documents(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), '/sendDocument'))->values()->all();
    }

    public function test_vault_round_trip_with_raw_key_and_passphrase(): void
    {
        $plain = $this->dir.'/p.bin';
        file_put_contents($plain, random_bytes(2_500_000)); // 3 bo'lak (1 MiB)
        foreach ([BackupVault::generateKey(), 'uzun-va-maxfiy-parol-ibora-2026'] as $secret) {
            BackupVault::encrypt($plain, $this->dir.'/p.enc', $secret);
            $this->assertTrue(BackupVault::isEncrypted($this->dir.'/p.enc'));
            $this->assertStringNotContainsString(substr((string) file_get_contents($plain), 0, 64), (string) file_get_contents($this->dir.'/p.enc'));
            BackupVault::decrypt($this->dir.'/p.enc', $this->dir.'/p.out', $secret);
            $this->assertSame(hash_file('sha256', $plain), hash_file('sha256', $this->dir.'/p.out'));
        }
    }

    public function test_vault_rejects_wrong_key_and_truncated_file(): void
    {
        $plain = $this->dir.'/p.bin';
        file_put_contents($plain, random_bytes(1_500_000));
        $key = BackupVault::generateKey();
        BackupVault::encrypt($plain, $this->dir.'/p.enc', $key);

        try {
            BackupVault::decrypt($this->dir.'/p.enc', $this->dir.'/x', BackupVault::generateKey());
            $this->fail('Wrong key must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString("kalit noto'g'ri", $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/x');

        // Oxirgi bo'lakni olib tashlash (Telegram bo'lagi yo'qolgani kabi) aniqlanadi.
        $data = (string) file_get_contents($this->dir.'/p.enc');
        $firstLen = unpack('N', substr($data, 33, 4))[1];
        file_put_contents($this->dir.'/cut.enc', substr($data, 0, 33 + 4 + $firstLen + 16));
        $this->expectExceptionMessage("to'liq emas");
        BackupVault::decrypt($this->dir.'/cut.enc', $this->dir.'/y', $key);
    }

    public function test_backup_encrypts_removes_plaintext_and_ships_to_admin_chat(): void
    {
        $this->artisan('tisu:backup', ['--force' => true, '--file' => $this->dumpFixture()])
            ->expectsOutputToContain('shifrlangan')->expectsOutputToContain("Telegram'ga yuborildi: 1 chat, 1 bo'lak")->assertSuccessful();

        $this->assertSame([], glob($this->dir.'/db-*.sql.gz'), 'Ochiq nusxa qolmasin');
        $enc = glob($this->dir.'/db-manual-*.sql.gz.enc');
        $this->assertCount(1, $enc);
        $this->assertTrue(BackupVault::isEncrypted($enc[0]));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($enc[0])), -4));

        $docs = $this->documents();
        $this->assertCount(1, $docs);
        $this->assertStringContainsString('chat_id', $docs[0]->body());
        $this->assertStringContainsString((string) self::ADMIN_CHAT, $docs[0]->body());
        $this->assertStringContainsString(hash_file('sha256', $enc[0]), $docs[0]->body());

        $last = SystemStatus::get('backup.last');
        $this->assertTrue($last['ok']);
        $this->assertTrue($last['encrypted']);
        $this->assertTrue($last['offsite']['ok']);
    }

    public function test_large_backup_is_split_into_telegram_parts_and_restores_from_parts(): void
    {
        app(Settings::class)->set(['backup.telegram_part_mb' => 5], null);
        $this->artisan('tisu:backup', ['--force' => true, '--file' => $this->dumpFixture(12_000_000)])
            ->expectsOutputToContain("3 bo'lak")->assertSuccessful();
        $this->assertCount(3, $this->documents());
        $this->assertStringContainsString('part1of3', $this->documents()[0]->body());

        // Telegram'dan yuklangan bo'laklarni simulyatsiya: shifrlangan faylni 5 MB dan kesamiz.
        $enc = glob($this->dir.'/db-manual-*.sql.gz.enc')[0];
        $parts = [];
        $h = fopen($enc, 'rb');
        for ($i = 1; ! feof($h); $i++) {
            $chunk = fread($h, 5 * 1048576);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            file_put_contents($parts[] = $this->dir."/part$i", $chunk);
        }
        fclose($h);
        $this->assertCount(3, $parts);

        $this->artisan('tisu:backup-restore', ['files' => $parts, '--dry-run' => true])
            ->expectsOutputToContain('Shifr ochildi')->expectsOutputToContain('2 jadval')
            ->expectsOutputToContain('users, courses')->expectsOutputToContain('Dry-run')->assertSuccessful();
        // Bo'lak yetishmasa — xato.
        $this->artisan('tisu:backup-restore', ['files' => [$parts[0], $parts[1]], '--dry-run' => true])->assertFailed();
    }

    public function test_without_key_backup_stays_local_and_admins_are_warned(): void
    {
        config(['backup.key' => '']);
        $this->artisan('tisu:backup', ['--force' => true, '--file' => $this->dumpFixture()])
            ->expectsOutputToContain('SHIFRLANMAGAN')->assertSuccessful();
        $this->assertCount(1, glob($this->dir.'/db-manual-*.sql.gz'));
        $this->assertSame([], $this->documents());
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage') && str_contains((string) $r['text'], 'BACKUP_ENCRYPTION_KEY'));
        $this->assertSame('unencrypted', SystemStatus::get('backup.last')['offsite']['skipped']);
    }

    public function test_failed_upload_alerts_admin(): void
    {
        $this->failDocuments = true;
        $this->artisan('tisu:backup', ['--force' => true, '--file' => $this->dumpFixture()])->assertSuccessful();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/sendMessage') && str_contains((string) $r['text'], "Telegram'ga yuborilmadi"));
        $this->assertFalse(SystemStatus::get('backup.last')['offsite']['ok']);
    }

    public function test_restore_requires_confirm_and_refuses_live_database_without_flag(): void
    {
        $this->artisan('tisu:backup', ['--force' => true, '--file' => $this->dumpFixture(), '--no-offsite' => true])->assertSuccessful();
        $enc = glob($this->dir.'/db-manual-*.sql.gz.enc')[0];

        // --confirm siz: faqat tekshiruv.
        $this->artisan('tisu:backup-restore', ['files' => [$enc]])->expectsOutputToContain('--confirm')->assertSuccessful();
        // Noto'g'ri kalit bilan ochilmaydi.
        config(['backup.key' => BackupVault::generateKey()]);
        $this->artisan('tisu:backup-restore', ['files' => [$enc], '--dry-run' => true])->expectsOutputToContain("kalit noto'g'ri")->assertFailed();
    }

    public function test_backup_list_and_key_commands(): void
    {
        $this->artisan('tisu:backup', ['--force' => true, '--file' => $this->dumpFixture(), '--no-offsite' => true])->assertSuccessful();
        $this->artisan('tisu:backup-list')->expectsOutputToContain('.sql.gz.enc')->assertSuccessful();
        $this->artisan('tisu:backup-key')->expectsOutputToContain('BACKUP_ENCRYPTION_KEY=base64:')->assertSuccessful();
    }
}
