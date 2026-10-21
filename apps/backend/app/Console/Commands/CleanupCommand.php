<?php

namespace App\Console\Commands;

use App\Models\StudyGroup;
use App\Support\SessionGenerator;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupCommand extends Command
{
    protected $signature = 'tisu:cleanup';

    protected $description = 'Eskirgan kirish so\'rovlari, tokenlar va eski bildirishnomalarni tozalash';

    public function handle(Settings $settings): int
    {
        $a = DB::table('telegram_login_requests')->where('expires_at', '<', now()->subDay())->delete();
        $b = DB::table('personal_access_tokens')->whereNotNull('expires_at')->where('expires_at', '<', now())->delete();
        $cutoff = now()->subDays($settings->int('automation.cleanup_days'));
        // Katta jadvallar bo'laklab o'chiriladi (bitta ulkan DELETE shared hostingda qulfni uzoq ushlab turmasin).
        $c = $this->chunked(fn () => DB::table('notifications_log')->where('created_at', '<', $cutoff));
        // Navbat: yuborilgan/yakunlangan xabarlar va tugagan tarqatish paketlari ham shu muddatdan keyin.
        $d = $this->chunked(fn () => DB::table('telegram_outbox')->where('status', '!=', 'pending')->where('updated_at', '<', $cutoff));
        DB::table('telegram_batches')->whereNotNull('finished_at')->where('finished_at', '<', $cutoff)->delete();
        // Audit jurnali cheksiz o'smasin (muddat sozlamada). Yangiliklarni o'qish belgilari o'chirilmaydi —
        // aks holda eski e'lonlar yana "o'qilmagan" bo'lib qolardi.
        $e = $this->chunked(fn () => DB::table('audit_logs')->where('created_at', '<', now()->subDays($settings->int('audit.retention_days'))));
        $g = $this->expiredCacheFiles();
        $this->info("Tozalandi: login so'rovlari $a, tokenlar $b, bildirishnomalar $c, navbat $d, audit $e, kesh fayllari $g");

        return self::SUCCESS;
    }

    /** @param \Closure(): \Illuminate\Database\Query\Builder $query */
    private function chunked(\Closure $query, int $size = 5000): int
    {
        $total = 0;
        do {
            $ids = $query()->limit($size)->pluck('id');
            $n = $ids->isEmpty() ? 0 : DB::table($query()->from)->whereIn('id', $ids)->delete();
            $total += $n;
        } while ($n === $size);

        return $total;
    }

    /** "file" kesh drayveri muddati o'tgan fayllarni o'zi o'chirmaydi (throttle, import va h.k.) — boshidagi 10 xonali muddat bo'yicha. */
    private function expiredCacheFiles(): int
    {
        if (config('cache.default') !== 'file') {
            return 0;
        }
        $dir = (string) config('cache.stores.file.path');
        $n = 0;
        if (! is_dir($dir)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getFilename() === '.gitignore') {
                continue;
            }
            $h = @fopen($file->getPathname(), 'rb');
            $expires = $h ? (int) fread($h, 10) : 0;
            if ($h) {
                fclose($h);
            }
            if ($expires > 0 && $expires < time() && @unlink($file->getPathname())) {
                $n++;
            }
        }

        return $n;
    }
}
