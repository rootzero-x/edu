<?php

namespace App\Console\Commands;

use App\Support\Settings;
use App\Telegram\BotAdmin;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Bazaning kunlik zaxira nusxasi: ~/tisu-backups/db-daily-YYYYmmdd-HHMM.sql.gz (700/600).
 * backup.keep_days dan eski db-*.sql.gz fayllar (deploy zaxiralari ham) o'chiriladi.
 */
class BackupCommand extends Command
{
    protected $signature = 'tisu:backup {--force : Sozlamada o\'chirilgan bo\'lsa ham}';

    protected $description = 'Bazadan zaxira nusxa olish va eskilarini tozalash';

    public function handle(Settings $settings, BotAdmin $admin): int
    {
        if (! $settings->bool('backup.enabled') && ! $this->option('force')) {
            $this->line("O'chirilgan (backup.enabled).");

            return self::SUCCESS;
        }
        $db = config('database.connections.'.config('database.default'));
        if (($db['driver'] ?? '') !== 'mysql' && ($db['driver'] ?? '') !== 'mariadb') {
            $this->warn('Faqat MySQL/MariaDB uchun.');

            return self::SUCCESS;
        }
        $dir = rtrim((string) env('BACKUP_DIR', (getenv('HOME') ?: dirname(base_path(), 2)).'/tisu-backups'), '/');
        try {
            if (! is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            @chmod($dir, 0700);
            $file = $dir.'/db-daily-'.now()->format('Ymd-Hi').'.sql.gz';
            $this->dump($db, $file);
            $this->info('Zaxira: '.basename($file).' ('.round(filesize($file) / 1024).' KB)');
        } catch (Throwable $e) {
            $msg = mb_substr($e->getMessage(), 0, 300);
            $this->error($msg);
            $admin->alert(strtr((string) $settings->get('telegram.text.alert_backup_failed'), ['{message}' => $msg]));

            return self::FAILURE;
        }

        $cutoff = now()->subDays($settings->int('backup.keep_days'))->getTimestamp();
        $removed = 0;
        foreach (glob($dir.'/db-*.sql.gz') ?: [] as $f) {
            if (filemtime($f) < $cutoff && @unlink($f)) {
                $removed++;
            }
        }
        $this->line("Eski zaxiralar o'chirildi: $removed");

        return self::SUCCESS;
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
