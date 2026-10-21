<?php

namespace App\Support;

use App\Domain\Role;
use App\Models\BillingEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * To'lovlar: kunlik hisoblash, narx tarixi va balanslar.
 *
 * Qoidalar:
 *  - Har bir faol guruh a'zoligi (bitta guruh = bitta kurs) uchun har kuni oylik narx / oydagi kunlar soni hisoblanadi.
 *    Butun so'mga yaxlitlash qoldig'i oyning oxirgi kuniga qo'shiladi — oy bo'yicha jami aynan oylik narxga teng.
 *  - Narx o'zgarsa, billing_fee_history ga yoziladi va faqat o'sha kundan boshlab qo'llanadi;
 *    allaqachon hisoblangan kunlar o'zgarmaydi (jurnal o'zgarmas).
 *  - Balans = to'lovlar + tuzatishlar − hisoblanganlar (bekor qilinganlar hisobga olinmaydi). Manfiy — qarz.
 */
final class Billing
{
    /** @var list<object{effective_from:string, monthly_fee:int}>|null */
    private ?array $feeHistory = null;

    public function __construct(private Settings $settings) {}

    /** Bitta kunning to'lovi: oylik narx / oydagi kunlar; qoldiq oxirgi kunga. */
    public static function dailyCharge(int $monthlyFee, CarbonInterface $day): int
    {
        $days = $day->daysInMonth;
        $base = intdiv($monthlyFee, $days);

        return $day->day === $days ? $monthlyFee - $base * ($days - 1) : $base;
    }

    /** Pul summasini ko'rsatish: 400 000 so'm. */
    public function money(int $amount): string
    {
        return ($amount < 0 ? '-' : '').number_format(abs($amount), 0, '.', ' ').' '.$this->settings->get('billing.currency');
    }

    /** Shu kunda amal qilgan oylik narx (tarix bo'lmasa — joriy sozlama). */
    public function feeOn(string $date): int
    {
        $this->feeHistory ??= DB::table('billing_fee_history')->orderBy('effective_from')->get(['effective_from', 'monthly_fee'])
            ->map(fn ($r) => (object) ['effective_from' => substr((string) $r->effective_from, 0, 10), 'monthly_fee' => (int) $r->monthly_fee])->all();
        $fee = null;
        foreach ($this->feeHistory as $row) {
            if ($row->effective_from > $date) {
                break;
            }
            $fee = $row->monthly_fee;
        }

        return $fee ?? ($this->feeHistory[0]->monthly_fee ?? $this->settings->int('billing.monthly_fee'));
    }

    /**
     * Narx o'zgarishini tarixga yozish (Settings::set / reset ichidan, o'sha tranzaksiyada).
     * Birinchi o'zgarishda eski narx ham "boshidan beri" deb yoziladi — o'tgan kunlar eski narxda qoladi.
     */
    public static function recordFeeChange(int $old, int $new, ?int $actorId): void
    {
        if ($old === $new) {
            return;
        }
        if (! DB::table('billing_fee_history')->exists()) {
            DB::table('billing_fee_history')->insert(['effective_from' => '2000-01-01', 'monthly_fee' => $old, 'created_by' => $actorId, 'created_at' => now()]);
        }
        DB::table('billing_fee_history')->updateOrInsert(
            ['effective_from' => now()->toDateString()],
            ['monthly_fee' => $new, 'created_by' => $actorId, 'created_at' => now()],
        );
    }

    /**
     * [from..to] kunlari uchun hisoblash. Takroran chaqirilsa ham bir kun ikki marta hisoblanmaydi (dedupe_key).
     *
     * @return array{days:int, memberships:int, created:int, amount:int}
     */
    public function accrue(CarbonInterface $from, CarbonInterface $to, bool $dryRun = false): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $members = $this->members($fromDate, $toDate);

        $now = now();
        $created = 0;
        $total = 0;
        // Har kun alohida yoziladi: uzoq "catch-up" (masalan, bir yil) ham xotirani to'ldirmaydi.
        $flush = function (array $rows) use ($dryRun, &$created, &$total): void {
            foreach (array_chunk($rows, 500) as $chunk) {
                $existing = DB::table('billing_entries')->whereIn('dedupe_key', array_column($chunk, 'dedupe_key'))->pluck('dedupe_key')->flip();
                $new = array_values(array_filter($chunk, fn ($r) => ! isset($existing[$r['dedupe_key']])));
                if (! $new) {
                    continue;
                }
                if ($dryRun) {
                    $created += count($new);
                } else {
                    // Parallel ishga tushsa ham unique kalit dublikatga yo'l qo'ymaydi.
                    $created += DB::table('billing_entries')->insertOrIgnore($new);
                }
                $total += array_sum(array_column($new, 'amount'));
            }
        };
        for ($day = Carbon::parse($fromDate); $day->toDateString() <= $toDate; $day = $day->addDay()) {
            $date = $day->toDateString();
            $amount = self::dailyCharge($this->feeOn($date), $day);
            if ($amount <= 0) {
                continue;
            }
            $rows = [];
            foreach ($members as $m) {
                if (! self::chargesOn($m, $date)) {
                    continue;
                }
                $rows[] = [
                    'student_id' => $m->student_id, 'group_id' => $m->group_id, 'type' => BillingEntry::CHARGE,
                    'amount' => -$amount, 'entry_date' => $date, 'dedupe_key' => "c:{$m->student_id}:{$m->group_id}:$date",
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $flush($rows);
        }

        return ['days' => Carbon::parse($fromDate)->diffInDays(Carbon::parse($toDate)) + 1, 'memberships' => $members->unique(fn ($m) => $m->group_id.':'.$m->student_id)->count(), 'created' => $created, 'amount' => -$total];
    }

    /**
     * [from..to] bilan kesishgan, hisoblanadigan a'zolik qatorlari (faqat ACTIVE kunlar: muzlatilgan, chiqib ketgan,
     * bitirgan kunlar — yo'q; Enrollments::chargeable). Faol guruh va o'quvchi roli shart.
     */
    private function members(string $fromDate, string $toDate, ?int $groupId = null, ?int $studentId = null, bool $currentOnly = true): \Illuminate\Support\Collection
    {
        // O'tgan kunlarni qayta ko'rishda (reconcile) guruhning hozirgi "faol"ligi va foydalanuvchining hozirgi roli
        // hisobga olinmaydi — o'sha kunlar a'zolik tarixi va guruh sanalari bo'yicha baholanadi.
        $groups = DB::table('study_groups')->when($currentOnly, fn ($q) => $q->where('active', true))
            ->when($groupId, fn ($q) => $q->where('id', $groupId))
            ->where('start_date', '<=', $toDate)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $fromDate))
            ->get(['id', 'start_date', 'end_date'])->keyBy('id');
        $students = DB::table('users')->when($currentOnly, fn ($q) => $q->where('role', Role::Student->value))
            ->when($studentId, fn ($q) => $q->where('id', $studentId))->pluck('id')->flip();

        return Enrollments::chargeable($fromDate, $toDate)
            ->filter(fn ($m) => isset($groups[$m->group_id], $students[$m->student_id]))
            ->map(fn ($m) => (object) [
                'group_id' => $m->group_id, 'student_id' => $m->student_id,
                'joined' => $m->from, 'left' => $m->to, 'skip_same_day' => $m->skip_same_day,
                'start' => substr((string) $groups[$m->group_id]->start_date, 0, 10),
                'end' => $groups[$m->group_id]->end_date ? substr((string) $groups[$m->group_id]->end_date, 0, 10) : null,
            ])->values();
    }

    /**
     * Shu a'zolik qatori bo'yicha kun hisoblanadimi. Qo'shilgan va chiqib ketgan kunlar ham hisoblanadi
     * (tongdagi hisoblash bilan bir xil natija); o'sha kuni qo'shilib, o'sha kuni chiqarilgan a'zolik (xato qo'shish) — yo'q.
     */
    private static function chargesOn(object $m, string $date): bool
    {
        return ! ($m->joined > $date || $m->start > $date || ($m->end !== null && $m->end < $date)
            || ($m->left !== null && ($m->left < $date || ($m->skip_same_day && $m->left === $m->joined))));
    }

    /**
     * A'zolikning hozirgi holati bo'yicha [from..to] dagi hisoblanishi kerak bo'lgan kunlar (accrue bilan aynan bir qoida).
     *
     * @return array<string, true>
     */
    public function chargeableDays(int $groupId, int $studentId, string $from, string $to): array
    {
        $members = $this->members($from, $to, $groupId, $studentId, currentOnly: false);
        $days = [];
        for ($day = Carbon::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $date = $day->toDateString();
            if ($members->contains(fn ($m) => self::chargesOn($m, $date))) {
                $days[$date] = true;
            }
        }

        return $days;
    }

    public function balance(int $studentId): int
    {
        return (int) DB::table('billing_entries')->where('student_id', $studentId)->whereNull('voided_at')->sum('amount');
    }

    /** O'quvchilar va ularning balansi (users + jurnal yig'indisi). Balans ustuni: balance. */
    public function balancesQuery(): Builder
    {
        $sums = DB::table('billing_entries')->whereNull('voided_at')->groupBy('student_id')
            ->selectRaw('student_id, sum(amount) as balance, max(case when type = ? then entry_date end) as last_payment_date', [BillingEntry::PAYMENT]);

        return DB::table('users')
            ->leftJoinSub($sums, 'b', 'b.student_id', '=', 'users.id')
            ->where('users.role', Role::Student->value);
    }

    /** Ko'ruvchi shu o'quvchining balansini ko'ra oladimi: boshqaruvchi yoki o'quvchining o'zi. */
    public static function canView(User $viewer, int $studentId): bool
    {
        return $viewer->hasPermission('billing.manage') || $viewer->id === $studentId;
    }

    /** Ko'ruvchining o'zi (o'quvchi) — "Balans" kartochkasi uchun. @return list<int> */
    public static function ownStudentIds(User $viewer): array
    {
        return $viewer->role === Role::Student ? [$viewer->id] : [];
    }

    /** Hisoblash boshlanadigan sana (sozlama bo'sh bo'lsa null). */
    public function startDate(): ?Carbon
    {
        $v = trim((string) $this->settings->get('billing.accrual_start_date'));

        return $v === '' ? null : Carbon::parse($v)->startOfDay();
    }
}
