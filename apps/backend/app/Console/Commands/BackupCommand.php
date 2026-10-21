<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupShipper;
use App\Support\Backup\BackupVault;
use App\Support\Settings;
use App\Support\SystemStatus;
use App\Support\Uploads;
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
        $file = null;
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
            $file = $this->seal($file);
            $status += ['file' => basename($file), 'size' => filesize($file), 'encrypted' => str_ends_with($file, '.enc')];
            $status['ok'] = true;
            $this->info('Zaxira: '.basename($file).' ('.BackupShipper::human((int) filesize($file)).')'.($status['encrypted'] ? ', shifrlangan' : ', SHIFRLANMAGAN'));
        } catch (Throwable $e) {
            // Yarim yozilgan / shifrlanmagan qoldiqlar qolmasin.
            // (shifrlashdagi xatoda seal() o'zi tozalaydi; bu yerda — dump/nusxa qoldiqlari).
            if ($file !== null) {
                @unlink($file.'.part');
                if (! str_ends_with($file, '.enc')) {
                    @unlink($file);
                }
            }
            $msg = mb_substr($e->getMessage(), 0, 300);
            $this->error($msg);
            SystemStatus::put('backup.last', $status + ['error' => $msg]);
            $admin->alert(strtr((string) $settings->get('telegram.text.alert_backup_failed'), ['{message}' => $msg]));

            return self::FAILURE;
        }

        $status['offsite'] = $this->offsite($settings, $admin, $shipper, $file, $status['encrypted']);
        if ($settings->bool('backup.include_files') && ! $this->option('file')) {
            $status['files'] = $this->files($dir, $settings, $admin, $shipper);
        }
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

    /** Kalit bo'lsa shifrlaydi (ochiq nusxa o'chiriladi). @return string yakuniy fayl yo'li */
    private function seal(string $file): string
    {
        if (! BackupVault::keyConfigured()) {
            return $file;
        }
        try {
            BackupVault::encrypt($file, $file.'.enc');
        } catch (Throwable $e) {
            @unlink($file.'.enc');
            @unlink($file);
            throw $e;
        }
        @unlink($file);

        return $file.'.enc';
    }

    /** Yuklangan fayllar arxivi (tar.gz → shifrlangan) va tashqariga yuborish. Xato bo'lsa — ogohlantirish, baza zaxirasi bekor qilinmaydi. */
    private function files(string $dir, Settings $settings, BotAdmin $admin, BackupShipper $shipper): array
    {
        $roots = array_filter(Uploads::backupPaths(), fn ($p) => is_dir($p) && (new \FilesystemIterator($p))->valid());
        if (! $roots) {
            return ['ok' => true, 'skipped' => 'empty'];
        }
        $file = $dir.'/files-daily-'.now()->format('Ymd-Hi').'.tar.gz';
        try {
            $args = ['tar', '-czf', $file.'.part'];
            foreach ($roots as $root) {
                array_push($args, '-C', $root, '.');
            }
            $p = new Process($args, null, null, null, 900);
            $p->run();
            if (! $p->isSuccessful() || ! is_file($file.'.part') || filesize($file.'.part') === 0) {
                throw new \RuntimeException('tar: '.trim($p->getErrorOutput() ?: 'exit '.$p->getExitCode()));
            }
            rename($file.'.part', $file);
            @chmod($file, 0600);
            $file = $this->seal($file);
            $result = ['ok' => true, 'file' => basename($file), 'size' => filesize($file), 'encrypted' => str_ends_with($file, '.enc')];
            $result['offsite'] = $this->offsite($settings, $admin, $shipper, $file, $result['encrypted']);
            $this->info('Fayllar zaxirasi: '.basename($file).' ('.BackupShipper::human((int) filesize($file)).')');

            return $result;
        } catch (Throwable $e) {
            @unlink($file.'.part');
            @unlink($file);
            $msg = mb_substr($e->getMessage(), 0, 300);
            $this->error($msg);
            $admin->alert(strtr((string) $settings->get('telegram.text.alert_backup_failed'), ['{message}' => 'Fayllar: '.$msg]));

            return ['ok' => false, 'error' => $msg];
        }
    }

    private function cleanup(string $dir, int $keepDays): void
    {
        $cutoff = now()->subDays($keepDays)->getTimestamp();
        $removed = 0;
        foreach (array_merge(glob($dir.'/db-*.sql.gz') ?: [], glob($dir.'/db-*.sql.gz.enc') ?: [],
            glob($dir.'/files-*.tar.gz') ?: [], glob($dir.'/files-*.tar.gz.enc') ?: []) as $f) {
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
        $tail = '';
        $writeFailed = false;
        // Parol buyruq satrida emas, muhit o'zgaruvchisida (ps orqali ko'rinmaydi).
        $p = new Process($args, null, ['MYSQL_PWD' => (string) $db['password']], null, 900);
        $p->run(function ($type, $buf) use ($gz, &$err, &$tail, &$writeFailed) {
            if ($type !== Process::OUT) {
                $err .= $buf;

                return;
            }
            // Disk to'lsa gzwrite 0/false qaytaradi — bunday nusxa "muvaffaqiyatli" deb hisoblanmaydi.
            if ($buf !== '' && ! gzwrite($gz, $buf)) {
                $writeFailed = true;
            }
            $tail = substr($tail.$buf, -512);
        });
        $closed = gzclose($gz);
        // mysqldump to'liq tugaganini oxirgi qatordagi belgi bilan tekshiramiz.
        $complete = str_contains($tail, '-- Dump completed');
        if (! $p->isSuccessful() || $writeFailed || ! $closed || ! $complete) {
            @unlink($file.'.part');
            $why = ! $p->isSuccessful() ? trim($err ?: 'exit '.$p->getExitCode())
                : ($writeFailed || ! $closed ? "faylga yozib bo'lmadi (disk to'lgan bo'lishi mumkin)" : "dump to'liq emas (yakunlovchi belgi yo'q)");
            throw new \RuntimeException('mysqldump: '.$why);
        }
        rename($file.'.part', $file);
        @chmod($file, 0600);
    }
}
