<?php

namespace App\Console\Commands;

use App\Support\Billing;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;

/** Qarzdor o'quvchilarga Telegram eslatma — billing.debt_reminder_every_days kunda bir marta. */
class BillingRemindDebtsCommand extends Command
{
    protected $signature = 'tisu:billing-remind-debts
        {--dry-run : Hech narsa yubormasdan kimga nima ketishini ko\'rsatish}
        {--skip : Hozirgi navbatni yubormasdan "o\'tkazildi" deb belgilash}';

    protected $description = "Qarzdorlarga to'lov eslatmasi";

    public function handle(Settings $settings, Billing $billing, Notifier $notifier): int
    {
        if (! $settings->bool('billing.enabled') || ! $settings->bool('billing.debt_reminder')) {
            return self::SUCCESS;
        }
        $min = $settings->int('billing.debt_reminder_min');
        // Davr raqami: shu davr ichida har kim bir martadan ko'p eslatma olmaydi (notifications_log orqali).
        $bucket = intdiv((int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse('2000-01-01')->startOfDay(), true), $settings->int('billing.debt_reminder_every_days'));
        $kind = "billing.debt:$bucket";
        $fee = $billing->money($billing->feeOn(now()->toDateString()));

        $debtors = $billing->balancesQuery()->where('users.active', true)->whereRaw('coalesce(b.balance, 0) <= ?', [-$min])
            ->get(['users.id', 'users.first_name', 'users.last_name', 'b.balance']);

        $items = [];
        foreach ($debtors as $d) {
            $vars = ['debt' => $billing->money(-(int) $d->balance), 'balance' => $billing->money((int) $d->balance), 'fee' => $fee];
            $items[] = ['user_id' => (int) $d->id, 'kind' => $kind, 'key' => 'telegram.text.billing_debt_reminder', 'vars' => $vars + ['name' => $d->first_name]];
        }
        AutomationCommands::deliver($this, $notifier, $items);

        return self::SUCCESS;
    }
}
