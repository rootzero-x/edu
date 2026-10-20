<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupVault;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Zaxiradan tiklash (va tiklash mashqi). Telegram'dan yuklangan bo'laklar ketma-ket beriladi:
 *   php artisan tisu:backup-restore db-daily-…sql.gz.enc.part1of2 db-daily-…sql.gz.enc.part2of2 --dry-run
 *   php artisan tisu:backup-restore db-daily-…sql.gz.enc --into=scratch_db --confirm
 * --confirm bo'lmasa hech narsa yozilmaydi (faqat tekshiruv). Ishlayotgan bazaga tiklash uchun qo'shimcha --live kerak.
 */
class BackupRestoreCommand extends Command
{
    protected $signature = 'tisu:backup-restore
        {files* : Zaxira fayli yoki uning bo\'laklari (tartib bilan)}
        {--into= : Qaysi bazaga tiklash (bo\'sh — joriy baza, faqat --live bilan)}
        {--dry-run : Faqat ochish va tekshirish: jadvallar va yozuvlar soni}
        {--confirm : Haqiqatan tiklash (bazadagi jadvallar zaxiradagisi bilan almashtiriladi)}
        {--live : Ishlayotgan (joriy) bazaga tiklashga ruxsat}
        {--keep-sql : Ochilgan .sql faylini saqlab qolish (tekshirish uchun)}';

    protected $description = 'Shifrlangan zaxirani ochish va bazaga tiklash (avval --dry-run)';

    public function handle(): int
    {
        $files = (array) $this->argument('files');
        foreach ($files as $f) {
            if (! is_file($f) || ! is_readable($f)) {
                $this->error("Fayl topilmadi: $f");

                return self::FAILURE;
            }
        }
        $work = rtrim((string) config('backup.dir'), '/').'/restore-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
        if (! @mkdir($work, 0700, true) && ! is_dir($work)) {
            $this->error("Ish papkasini yaratib bo'lmadi: $work");

            return self::FAILURE;
        }
        $temps = [];
        try {
            // 1) Bo'laklarni birlashtirish.
            $joined = count($files) > 1 ? $temps[] = $this->join($files, $work.'/joined') : $files[0];
            $this->line('SHA-256: '.hash_file('sha256', $joined));
            // 2) Shifrni ochish.
            $gz = $joined;
            if (BackupVault::isEncrypted($joined)) {
                $gz = $temps[] = $work.'/dump.sql.gz';
                BackupVault::decrypt($joined, $gz);
                $this->info('Shifr ochildi.');
            }
            // 3) Tekshirish.
            $stats = $this->inspect($gz);
            $this->info(sprintf("Dump: %s, %d qator, %d jadval, %d INSERT.", $this->human($stats['bytes']), $stats['lines'], count($stats['tables']), $stats['inserts']));
            $this->line('Jadvallar: '.implode(', ', $stats['tables']));
            if (! $stats['complete']) {
                $this->warn("Dump oxirida \"Dump completed\" belgisi yo'q — fayl to'liq bo'lmasligi mumkin.");
            }
            if ($this->option('keep-sql')) {
                $sql = $work.'/dump.sql';
                $this->gunzipTo($gz, $sql);
                $this->line("SQL: $sql");
            }
            if ($this->option('dry-run') || ! $this->option('confirm')) {
                $this->line($this->option('dry-run') ? 'Dry-run: bazaga hech narsa yozilmadi.' : "Tiklash uchun --into=<baza> --confirm qo'shing (bazaga hech narsa yozilmadi).");

                return $stats['tables'] ? self::SUCCESS : self::FAILURE;
            }

            return $this->restore($gz);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            foreach ($temps as $t) {
                @unlink($t);
            }
            if (! $this->option('keep-sql')) {
                @rmdir($work);
            }
        }
    }

    private function restore(string $gz): int
    {
        $db = config('database.connections.'.config('database.default'));
        if (! in_array($db['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            $this->error('Tiklash faqat MySQL/MariaDB uchun.');

            return self::FAILURE;
        }
        $target = (string) ($this->option('into') ?: $db['database']);
        if (! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $target)) {
            $this->error("Baza nomi noto'g'ri: $target");

            return self::FAILURE;
        }
        if ($target === $db['database'] && ! $this->option('live')) {
            $this->error("«$target» — ishlayotgan baza. Mashq uchun alohida bo'sh baza yarating va --into=<nomi> bering; haqiqiy tiklash uchun --live qo'shing.");

            return self::FAILURE;
        }
        $this->warn("«$target» bazasidagi jadvallar zaxiradagi holatga almashtiriladi.");
        $which = new Process(['sh', '-c', 'command -v mysql']);
        $which->run();
        $bin = trim($which->getOutput()) ?: 'mysql';
        $p = new Process([$bin, '--default-character-set=utf8mb4', '-h', (string) ($db['host'] ?? 'localhost'), '-P', (string) ($db['port'] ?? '3306'),
            '-u', (string) $db['username'], $target], null, ['MYSQL_PWD' => (string) $db['password']], null, 3600);
        $p->setInput($this->chunks($gz));
        $err = '';
        $p->run(function ($type, $buf) use (&$err) {
            if ($type === Process::ERR) {
                $err .= $buf;
            }
        });
        if (! $p->isSuccessful()) {
            $this->error('mysql: '.trim($err ?: 'exit '.$p->getExitCode()));

            return self::FAILURE;
        }
        $this->info("Tiklandi: «$target».");
        \App\Support\Audit::as(null, 'backup.restore', 'database', null, ['into' => $target]);

        return self::SUCCESS;
    }

    /** @return \Generator<string> */
    private function chunks(string $gz): \Generator
    {
        $h = gzopen($gz, 'rb');
        try {
            while (! gzeof($h)) {
                yield (string) gzread($h, 1048576);
            }
        } finally {
            gzclose($h);
        }
    }

    /** @return array{bytes: int, lines: int, inserts: int, tables: list<string>, complete: bool} */
    private function inspect(string $gz): array
    {
        $h = @gzopen($gz, 'rb');
        if (! $h) {
            throw new \RuntimeException("Dump faylini ochib bo'lmadi (gzip emas?).");
        }
        $stats = ['bytes' => 0, 'lines' => 0, 'inserts' => 0, 'tables' => [], 'complete' => false];
        while (($line = gzgets($h, 1048576)) !== false) {
            $stats['bytes'] += strlen($line);
            $stats['lines']++;
            if (str_starts_with($line, 'CREATE TABLE') && preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
                $stats['tables'][] = $m[1];
            } elseif (str_starts_with($line, 'INSERT INTO')) {
                $stats['inserts']++;
            }
            if (str_contains($line, '-- Dump completed')) {
                $stats['complete'] = true;
            }
        }
        gzclose($h);
        if ($stats['lines'] === 0) {
            throw new \RuntimeException("Dump bo'sh yoki gzip emas.");
        }

        return $stats;
    }

    private function gunzipTo(string $gz, string $out): void
    {
        $dst = fopen($out, 'wb');
        foreach ($this->chunks($gz) as $c) {
            fwrite($dst, $c);
        }
        fclose($dst);
        @chmod($out, 0600);
    }

    /** @param list<string> $files */
    private function join(array $files, string $out): string
    {
        $dst = fopen($out, 'wb');
        foreach ($files as $f) {
            $src = fopen($f, 'rb');
            stream_copy_to_stream($src, $dst);
            fclose($src);
        }
        fclose($dst);
        @chmod($out, 0600);

        return $out;
    }

    private function human(int $bytes): string
    {
        return \App\Support\Backup\BackupShipper::human($bytes);
    }
}
