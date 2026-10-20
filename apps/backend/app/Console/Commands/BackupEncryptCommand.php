<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupVault;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mavjud zaxira faylini joyida shifrlash: <fayl> → <fayl>.enc (600), ochiq nusxa o'chiriladi.
 * Deploy skripti har deploydagi baza nusxasini (db-<REL>.sql.gz) shu bilan shifrlaydi.
 * Telegram'ga yubormaydi va "oxirgi zaxira" holatini o'zgartirmaydi (bu — kunlik tisu:backup ishi).
 */
class BackupEncryptCommand extends Command
{
    protected $signature = 'tisu:backup-encrypt {files* : Shifrlanadigan fayl(lar)}';

    protected $description = 'Zaxira faylini BACKUP_ENCRYPTION_KEY bilan shifrlash va ochiq nusxasini o\'chirish';

    public function handle(): int
    {
        if (! BackupVault::keyConfigured()) {
            $this->warn("BACKUP_ENCRYPTION_KEY yo'q — fayllar shifrlanmadi.");

            return self::FAILURE;
        }
        $failed = false;
        foreach ((array) $this->argument('files') as $file) {
            try {
                if (! is_file($file)) {
                    throw new \RuntimeException('topilmadi');
                }
                if (BackupVault::isEncrypted($file)) {
                    $this->line("Allaqachon shifrlangan: $file");

                    continue;
                }
                BackupVault::encrypt($file, $file.'.enc');
                // Ochishni tekshirmasdan ochiq nusxani o'chirmaymiz.
                $probe = $file.'.probe';
                BackupVault::decrypt($file.'.enc', $probe);
                $same = hash_file('sha256', $probe) === hash_file('sha256', $file);
                @unlink($probe);
                if (! $same) {
                    @unlink($file.'.enc');
                    throw new \RuntimeException("tekshiruvda mos kelmadi — ochiq nusxa qoldirildi");
                }
                unlink($file);
                $this->info("Shifrlandi: $file.enc");
            } catch (Throwable $e) {
                $failed = true;
                $this->error("$file: ".$e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
