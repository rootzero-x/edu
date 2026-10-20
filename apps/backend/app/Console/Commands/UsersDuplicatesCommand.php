<?php

namespace App\Console\Commands;

use App\Support\Audit;
use App\Support\PhoneDuplicates;
use Illuminate\Console\Command;

/** Takroriy va normal bo'lmagan telefon raqamlari hisoboti; --fix bilan raqamlarni normal shaklga keltiradi (to'qnashuvsizlarini). */
class UsersDuplicatesCommand extends Command
{
    protected $signature = 'tisu:users-duplicates {--fix : Normal bo\'lmagan raqamlarni +998XXXXXXXXX ga keltirish (to\'qnashuv bo\'lmasa)}';

    protected $description = 'Takroriy akkauntlarni topish (telefon normalizatsiyasi bo\'yicha)';

    public function handle(PhoneDuplicates $duplicates): int
    {
        $report = $duplicates->report();
        $this->info('Bir xil telefon (normal shaklda): '.count($report['phone_groups']).' guruh');
        foreach ($report['phone_groups'] as $g) {
            $this->line("  {$g['phone']}");
            foreach ($g['users'] as $u) {
                $this->line(sprintf('    #%d %s  %s  %s%s', $u['id'], $u['phone'], $u['name'], $u['role'], $u['history'] ? '  [tarix bor]' : ''));
            }
        }
        $this->info("Bir xil ism-familiya, turli raqam: ".count($report['name_groups']).' guruh');
        foreach ($report['name_groups'] as $g) {
            $this->line("  {$g['name']}: ".implode(', ', array_map(fn ($u) => "#{$u['id']} {$u['phone']}", $g['users'])));
        }
        $result = $duplicates->normalizeAll((bool) $this->option('fix'));
        $this->info(($this->option('fix') ? "To'g'rilandi" : "To'g'rilanadi (--fix)").': '.count($result['fixed']));
        foreach ($result['fixed'] as $u) {
            $this->line("  #{$u['id']} {$u['phone']} → {$u['normalized']}");
        }
        foreach ($result['conflicts'] as $u) {
            $this->warn("  To'qnashuv: #{$u['id']} {$u['phone']} → {$u['normalized']} (shu raqamli akkaunt bor — saytda birlashtiring)");
        }
        foreach ($result['invalid'] as $u) {
            $this->warn("  Yaroqsiz raqam: #{$u['id']} {$u['phone']}");
        }
        if ($this->option('fix') && $result['fixed']) {
            Audit::as(null, 'user.normalize_phones', 'user', null, ['count' => count($result['fixed'])]);
        }

        return self::SUCCESS;
    }
}
