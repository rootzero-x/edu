<?php

namespace App\Console\Commands;

use App\Domain\Role;
use App\Support\Billing;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Oylik to'lov muddati eslatmasi. To'lov kuni — guruhga qo'shilgan sana (guruh keyin boshlangan bo'lsa,
 * guruh boshlanish sanasi) dan har oy shu sanada. billing.due_reminder_days (masalan [3, 1]) kun oldin
 * o'quvchiga Telegram xabar boradi: 1 kun qolganda — "ertaga", aks holda — "N kun qoldi".
 * Balansi keyingi oyni allaqachon qoplagan (oldindan to'lagan) o'quvchiga yuborilmaydi.
 */
class BillingRemindDebtsCommand extends Command
{
    protected $signature = 'tisu:billing-remind-debts
        {--dry-run : Hech narsa yubormasdan kimga nima ketishini ko\'rsatish}
        {--skip : Hozirgi navbatni yubormasdan "o\'tkazildi" deb belgilash}';

    protected $description = "Oylik to'lov muddati yaqinlashganda o'quvchiga eslatma";

    public function handle(Settings $settings, Billing $billing, Notifier $notifier): int
    {
        if (! $settings->bool('billing.enabled') || ! $settings->bool('billing.debt_reminder')) {
            return self::SUCCESS;
        }
        $days = array_values(array_unique(array_filter((array) $settings->get('billing.due_reminder_days'), fn ($d) => is_int($d) && $d > 0)));
        if (! $days) {
            return self::SUCCESS;
        }
        $today = now()->startOfDay();

        $members = DB::table('group_members')
            ->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->join('users', 'users.id', '=', 'group_members.student_id')
            ->where('users.role', Role::Student->value)->where('users.active', true)
            ->where('study_groups.active', true)->whereNull('group_members.left_at')
            ->get(['group_members.student_id', 'group_members.joined_at', 'study_groups.id as group_id', 'study_groups.name',
                'study_groups.start_date', 'study_groups.end_date', 'users.first_name']);
        $perStudent = $members->countBy('student_id');

        $items = [];
        $balances = [];
        foreach ($members as $m) {
            $anchor = Carbon::parse(max(substr((string) $m->joined_at, 0, 10), substr((string) $m->start_date, 0, 10)))->startOfDay();
            foreach ($days as $d) {
                $due = $today->copy()->addDays($d);
                if (! self::isDueDate($anchor, $due) || ($m->end_date && substr((string) $m->end_date, 0, 10) < $due->toDateString())) {
                    continue;
                }
                // To'lov kunida muzlatilgan o'quvchiga "to'lov kuni" eslatmasi yuborilmaydi.
                if (DB::table('enrollments')->where(['group_id' => $m->group_id, 'student_id' => $m->student_id, 'status' => 'FROZEN'])
                    ->whereNull('outcome')->where('starts_on', '<=', $due->toDateString())->where('ends_on', '>=', $due->toDateString())->exists()) {
                    continue;
                }
                $fee = $billing->feeOn($due->toDateString());
                $balance = $balances[$m->student_id] ??= $billing->balance((int) $m->student_id);
                // Keyingi oy(lar) uchun oldindan to'lagan — eslatma kerak emas.
                if ($fee > 0 && $balance >= $fee * $perStudent[$m->student_id]) {
                    continue;
                }
                $items[] = [
                    'user_id' => (int) $m->student_id,
                    'kind' => "billing.due:{$m->group_id}:{$due->toDateString()}:$d",
                    'key' => $d === 1 ? 'telegram.text.billing_due_tomorrow' : 'telegram.text.billing_due_soon',
                    'vars' => ['name' => $m->first_name, 'group' => $m->name, 'days' => $d, 'date' => $due->format('d.m.Y'),
                        'fee' => $billing->money($fee), 'balance' => $billing->money($balance)],
                ];
            }
        }
        AutomationCommands::deliver($this, $notifier, $items);

        return self::SUCCESS;
    }

    /** $due — $anchor dan butun oylar o'tgan sana (31-yanvar → 28/29-fevral, 31-mart …). */
    public static function isDueDate(Carbon $anchor, Carbon $due): bool
    {
        $months = ($due->year - $anchor->year) * 12 + ($due->month - $anchor->month);

        return $months >= 1 && $anchor->copy()->addMonthsNoOverflow($months)->isSameDay($due);
    }
}
