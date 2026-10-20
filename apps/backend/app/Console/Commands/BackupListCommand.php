<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupShipper;
use App\Support\Backup\BackupVault;
use App\Support\SystemStatus;
use Illuminate\Console\Command;

class BackupListCommand extends Command
{
    protected $signature = 'tisu:backup-list';

    protected $description = 'Lokal zaxiralar ro\'yxati va oxirgi zaxira holati';

    public function handle(): int
    {
        $dir = rtrim((string) config('backup.dir'), '/');
        $files = array_merge(glob($dir.'/db-*.sql.gz') ?: [], glob($dir.'/db-*.sql.gz.enc') ?: []);
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $this->table(['Fayl', 'Hajmi', 'Sana', 'Shifrlangan'], array_map(fn ($f) => [
            basename($f), BackupShipper::human((int) filesize($f)), date('d.m.Y H:i', (int) filemtime($f)), BackupVault::isEncrypted($f) ? 'ha' : "YO'Q",
        ], $files));
        $this->line("Papka: $dir");
        $this->line('Shifrlash kaliti: '.(BackupVault::keyConfigured() ? 'bor' : "YO'Q (BACKUP_ENCRYPTION_KEY)"));
        if ($last = SystemStatus::get('backup.last')) {
            $this->line('Oxirgi zaxira: '.json_encode($last, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
