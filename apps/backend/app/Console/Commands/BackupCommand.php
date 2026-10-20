<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupShipper;
use App\Support\Backup\BackupVault;
use App\Support\Settings;
use App\Support\SystemStatus;
use App\Telegram\BotAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Bazaning kunlik zaxira nusxasi:
 *  1) mysqldump → ~/tisu-backups/db-daily-YYYYmmdd-HHMM.sql.gz (700/600);
 *  2) BACKUP_ENCRYPTION_KEY bo'lsa — AES-256-GCM bilan shifrlanadi (.sql.gz.enc), ochiq nusxa o'chiriladi;
 *  3) backup.offsite_telegram yoqilgan bo'lsa — admin Telegram chat(lar)iga yuboriladi (serverdan tashqarida nusxa);
 *  4) backup.keep_days dan eski lokal db-*.sql.gz[.enc] fayllar (deploy zaxiralari ham) o'chiriladi.
 * Har qanday xato — adminlarga ogohlantirish. Natija system_status (backup.last) ga yoziladi: admin holat kartasida ko'rinadi.
 */
class BackupCommand extends Command
{
    protected $signature = 'tisu:backup
        {--force : Sozlamada o\'chirilgan bo\'lsa ham}
        {--file= : mysqldump o\'rniga mavjud .sql.gz faylini shifrlash va yuborish}
        {--no-offsite : Telegram\'ga yubormaslik}';

    protected $description = 'Bazadan zaxira nusxa olish (shifrlash, serverdan tashqariga yuborish) va eskilarini tozalash';

    public function handle(Settings $settings, BotAdmin $admin, BackupShipper $shipper): int
    {
        if (! $settings->bool('backup.enabled') && ! $this->option('force')) {
            $this->line("O'chirilgan (backup.enabled).");

            return self::SUCCESS;
        }
        $dir = rtrim((string) config('backup.dir'), '/');
        $status = ['at' => now()->toIso8601String(), 'ok' => false];
        $db = config('database.connections.'.config('database.default'));
        if (! $this->option('file') && ! in_array($db['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            $this->warn('Faqat MySQL/MariaDB uchun.');

            return self::SUCCESS;
        }
        try {
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            @chmod($dir, 0700);
            if ($source = $this->option('file')) {
                $file = $this->adopt((string) $source, $dir);
            } else {
                $file = $dir.'/db-daily-'.now()->format('Ymd-Hi').'.sql.gz';
                $this->dump($db, $file);
            }
            if (BackupVault::keyConfigured()) {
                BackupVault::encrypt($file, $file.'.enc');
                @unlink($file);
                $file .= '.enc';
            }
            $status += ['file' => basename($file), 'size' => filesize($file), 'encrypted' => str_ends_with($file, '.enc')];
            $status['ok'] = true;
            $this->info('Zaxira: '.basename($file).' ('.BackupShipper::human((int) filesize($file)).')'.($status['encrypted'] ? ', shifrlangan' : ', SHIFRLANMAGAN'));
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 300);
            $this->error($msg);
            SystemStatus::put('backup.last', $status + ['error' => $msg]);
            $admin->alert(strtr((string) $settings->get('telegram.text.alert_backup_failed'), ['{message}' => $msg]));

            return self::FAILURE;
        }

        $status['offsite'] = $this->offsite($settings, $admin, $shipper, $file, $status['encrypted']);
        SystemStatus::put('backup.last', $status);
        $this->cleanup($dir, $settings->int('backup.keep_days'));

        return self::SUCCESS;
    }

    /** @return array{ok: bool, skipped?: string, chats?: int, parts?: int, sha256?: string, error?: ?string} */
    private function offsite(Settings $settings, BotAdmin $admin, BackupShipper $shipper, string $file, bool $encrypted): array
    {
        if ($this->option('no-offsite') || ! $settings->bool('backup.offsite_telegram')) {
            return ['ok' => false, 'skipped' => 'disabled'];
        }
        if (! $encrypted && $settings->bool('backup.offsite_require_encryption')) {
            $this->warn('Shifrlash kaliti yo\'q — tashqariga yuborilmadi.');
            // Kuniga bir martadan ko'p eslatmaymiz.
            if (Cache::add('tisu.backup.unencrypted_alert', 1, now()->addHours(20))) {
                $admin->alert((string) $settings->get('telegram.text.alert_backup_unencrypted'));
            }

            return ['ok' => false, 'skipped' => 'unencrypted'];
        }
        if ((string) config('services.telegram.bot_token') === '') {
            return ['ok' => false, 'skipped' => 'bot_not_configured'];
        }
        $result = $shipper->toTelegram($file);
        if ($result['ok']) {
            $this->info("Telegram'ga yuborildi: {$result['chats']} chat, {$result['parts']} bo'lak.");
        } else {
            $this->error("Telegram'ga yuborilmadi: {$result['error']}");
            $admin->alert(strtr((string) $settings->get('telegram.text.alert_backup_offsite_failed'), ['{message}' => (string) $result['error']]));
        }

        return $result;
    }

    /** --file: mavjud dump nusxasi zaxira papkasiga olinadi (asl fayl o'zgarmaydi). */
    private function adopt(string $source, string $dir): string
    {
        if (! is_file($source) || ! is_readable($source)) {
            throw new \RuntimeException("Fayl topilmadi: $source");
        }
        $target = $dir.'/db-manual-'.now()->format('Ymd-His').'.sql.gz';
        $h = fopen($source, 'rb');
        $isGzip = fread($h, 2) === "\x1f\x8b";
        fclose($h);
        if ($isGzip) {
            if (! copy($source, $target)) {
                throw new \RuntimeException("Nusxa olib bo'lmadi: $source");
            }
        } else {
            // Oddiy .sql — siqib olamiz.
            $in = fopen($source, 'rb');
            $out = gzopen($target, 'wb6');
            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1048576));
            }
            fclose($in);
            gzclose($out);
        }
        @chmod($target, 0600);

        return $target;
    }

    private function cleanup(string $dir, int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $removed = 0;
        foreach (array_merge(glob($dir.'/db-*.sql.gz') ?: [], glob($dir.'/db-*.sql.gz.enc') ?: []) as $f) {
            if (filemtime($f) < $cutoff && @unlink($f)) {
                $removed++;
            }
        }
        $this->line("Eski zaxiralar o'chirildi: $removed");
    }

    private function dump(array $db, string $file): void
    {
        $bin = trim((string) (new Process(['sh', '-c', 'command -v mysqldump']))->mustRun()->getOutput()) ?: 'mysqldump';
        $args = [$bin, '--single-transaction', '--no-tablespaces', '--quick', '--default-character-set=utf8mb4',
            '-h', (string) ($db['host'] ?? 'localhost'), '-P', (string) ($db['port'] ?? '3306'), '-u', (string) $db['username'], (string) $db['database']];
        $gz = gzopen($file.'.part', 'wb6');
        if ($gz === false) {
            throw new \RuntimeException("Faylni ochib bo'lmadi: $file");
        }
        $err = '';
        // Parol buyruq satrida emas, muhit o'zgaruvchisida (ps orqali ko'rinmaydi).
        $p = new Process($args, null, ['MYSQL_PWD' => (string) $db['password']], null, 900);
        $p->run(function ($type, $buf) use ($gz, &$err) {
            $type === Process::OUT ? gzwrite($gz, $buf) : $err .= $buf;
        });
        gzclose($gz);
        if (! $p->isSuccessful()) {
            @unlink($file.'.part');
            throw new \RuntimeException('mysqldump: '.trim($err ?: 'exit '.$p->getExitCode()));
        }
        rename($file.'.part', $file);
        @chmod($file, 0600);
    }
}
