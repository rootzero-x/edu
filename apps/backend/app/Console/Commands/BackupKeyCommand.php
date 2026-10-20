<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupVault;
use Illuminate\Console\Command;

/** Yangi shifrlash kaliti. .env ga avtomatik yozilmaydi — admin o'zi qo'yadi va parol menejerida saqlaydi. */
class BackupKeyCommand extends Command
{
    protected $signature = 'tisu:backup-key';

    protected $description = 'Zaxira uchun yangi shifrlash kalitini chiqarish (BACKUP_ENCRYPTION_KEY)';

    public function handle(): int
    {
        $this->line('BACKUP_ENCRYPTION_KEY='.BackupVault::generateKey());
        $this->warn("Kalitni .env ga qo'ying va serverdan tashqarida (parol menejeri) saqlang: kalitsiz zaxirani ochib bo'lmaydi.");
        if (BackupVault::keyConfigured()) {
            $this->warn("Diqqat: kalit allaqachon bor. Almashtirsangiz, eski zaxiralar faqat ESKI kalit bilan ochiladi.");
        }

        return self::SUCCESS;
    }
}
