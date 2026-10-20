<?php

namespace App\Console\Commands;

use App\Support\Billing;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Kunlik hisoblash. Har kuni erta tongda ishlaydi va bugungi kunni hisoblaydi; server ishlamay qolgan bo'lsa,
 * billing.catchup_days kun orqaga qarab o'tkazib yuborilgan kunlarni ham to'ldiradi (bir kun ikki marta hisoblanmaydi).
 *
 *   php artisan tisu:billing-accrue                — bugungacha
 *   php artisan tisu:billing-accrue --dry-run      — hech narsa yozmasdan natijani ko'rsatish
 *   php artisan tisu:billing-accrue --date=2026-10-31 --from=2026-10-01  — qo'lda davr (boshlanish sanasidan oldin emas)
 */
class BillingAccrueCommand extends Command
{
    protected $signature = 'tisu:billing-accrue
        {--dry-run : Yozmasdan nechta yozuv yaratilishini ko\'rsatish}
        {--date= : Shu kungacha hisoblash (YYYY-MM-DD, standart — bugun)}
        {--from= : Shu kundan boshlab (catchup_days cheklovi o\'rniga)}';

    protected $description = "To'lovlar: kunlik hisoblash (o'tkazib yuborilgan kunlar bilan)";

    public function handle(Settings $settings, Billing $billing): int
    {
        if (! $settings->bool('billing.enabled')) {
            $this->line("To'lovlar tizimi o'chirilgan (billing.enabled).");

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');
        try {
            $today = now()->startOfDay();
            $to = $this->option('date') ? $this->parseDate((string) $this->option('date')) : $today;
            $from = $this->option('from') ? $this->parseDate((string) $this->option('from')) : null;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($to->greaterThan($today)) {
            $this->error("Kelajakdagi kunlarni hisoblab bo'lmaydi.");

            return self::FAILURE;
        }

        $start = $billing->startDate();
        if (! $start) {
            // Birinchi ishga tushish: o'tmish hisoblanmaydi, bugundan boshlanadi (sozlamada ko'rinadi va o'zgartirsa bo'ladi).
            $start = $today->copy();
            if (! $dry) {
                $settings->set(['billing.accrual_start_date' => $start->toDateString()], null);
                $this->info("Hisoblash boshlanish sanasi o'rnatildi: {$start->toDateString()}");
            }
        }
        $from ??= $to->copy()->subDays($settings->int('billing.catchup_days') - 1);
        if ($from->lessThan($start)) {
            $from = $start->copy();
        }
        if ($from->greaterThan($to)) {
            $this->line("Hisoblanadigan kun yo'q (boshlanish: {$start->toDateString()}).");

            return self::SUCCESS;
        }

        $r = $billing->accrue($from, $to, $dry);
        $this->info(sprintf('%s%s..%s: %d kun, %d a\'zolik, %d yangi yozuv, jami %s',
            $dry ? '[dry-run] ' : '', $from->toDateString(), $to->toDateString(), $r['days'], $r['memberships'], $r['created'], $billing->money($r['amount'])));

        return self::SUCCESS;
    }

    private function parseDate(string $v): Carbon
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new \InvalidArgumentException("Sana YYYY-MM-DD formatida bo'lishi kerak: $v");
        }

        return Carbon::parse($v)->startOfDay();
    }
}
