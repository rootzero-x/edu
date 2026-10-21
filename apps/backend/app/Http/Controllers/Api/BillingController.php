<?php

namespace App\Http\Controllers\Api;

use App\Domain\Role;
use App\Models\BillingEntry;
use App\Models\User;
use App\Support\Audit;
use App\Support\Billing;
use App\Support\Csv;
use App\Support\Settings;
use App\Telegram\Notifier;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * To'lovlar. Ko'rish: boshqaruvchi (billing.manage) — hammasi, o'quvchi — o'zi.
 * Kiritish/bekor qilish va ro'yxatlar — faqat billing.manage (route middleware).
 * Jurnal o'zgarmas: yozuvlar o'chirilmaydi, faqat bekor qilinadi.
 */
class BillingController
{
    public function __construct(private Billing $billing, private Settings $settings) {}

    /** O'quvchining o'z qisqa balansi (bosh sahifa, bot, Mini App). */
    public function me(Request $request): JsonResponse
    {
        $ids = Billing::ownStudentIds($request->user());
        $students = User::whereIn('id', $ids)->get()->sortBy(fn ($u) => array_search($u->id, $ids, true))->values();

        return response()->json([
            'enabled' => $this->settings->bool('billing.enabled'),
            'students' => $students->map(fn (User $s) => $this->summary($s))->all(),
        ]);
    }

    /** Bitta o'quvchi: balans, a'zoliklar, oylik xulosa va jurnal (sahifalab). */
    public function show(Request $request, User $student): JsonResponse
    {
        $viewer = $request->user();
        abort_unless(Billing::canView($viewer, $student->id), 403, "Bu o'quvchining to'lovlarini ko'rish huquqi yo'q.");
        abort_unless($student->role === Role::Student, 404);
        $data = $request->validate([
            'type' => ['nullable', 'in:CHARGE,PAYMENT,ADJUSTMENT,MANUAL'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $entries = BillingEntry::with('group:id,name')->where('student_id', $student->id)
            ->when($data['type'] ?? null, fn ($q, $t) => $t === 'MANUAL' ? $q->where('type', '!=', BillingEntry::CHARGE) : $q->where('type', $t))
            ->orderByDesc('entry_date')->orderByDesc('id')
            ->paginate($data['per_page'] ?? $this->settings->int('ui.page_size'));

        $totals = DB::table('billing_entries')->where('student_id', $student->id)->whereNull('voided_at')
            ->groupBy('type')->selectRaw('type, sum(amount) as s')->pluck('s', 'type');

        $months = DB::table('billing_entries')->where('student_id', $student->id)->whereNull('voided_at')
            ->where('entry_date', '>=', now()->startOfMonth()->subMonths(11)->toDateString())
            ->groupByRaw('substr(entry_date, 1, 7)')
            ->selectRaw("substr(entry_date, 1, 7) as month,
                sum(case when type = 'CHARGE' then -amount else 0 end) as charged,
                sum(case when type = 'PAYMENT' then amount else 0 end) as paid,
                sum(case when type = 'ADJUSTMENT' then amount else 0 end) as adjusted")
            ->orderByDesc('month')->get()
            ->map(fn ($r) => ['month' => $r->month, 'charged' => (int) $r->charged, 'paid' => (int) $r->paid, 'adjusted' => (int) $r->adjusted]);

        $today = now();
        $daily = Billing::dailyCharge($this->billing->feeOn($today->toDateString()), $today);
        $groups = DB::table('group_members')->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->where('group_members.student_id', $student->id)->whereNull('group_members.left_at')
            ->orderBy('study_groups.name')
            ->get(['study_groups.id', 'study_groups.name', 'study_groups.active', 'group_members.joined_at'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'name' => $g->name, 'active' => (bool) $g->active,
                'joined_at' => substr((string) $g->joined_at, 0, 10), 'daily_charge' => $g->active ? $daily : 0]);

        return response()->json($this->summary($student) + [
            'groups' => $groups,
            'monthly_fee' => $this->billing->feeOn($today->toDateString()),
            'totals' => [
                'charged' => -(int) ($totals[BillingEntry::CHARGE] ?? 0),
                'paid' => (int) ($totals[BillingEntry::PAYMENT] ?? 0),
                'adjusted' => (int) ($totals[BillingEntry::ADJUSTMENT] ?? 0),
            ],
            'months' => $months,
            'entries' => $entries->getCollection()->map->toApi(),
            'meta' => ['total' => $entries->total(), 'page' => $entries->currentPage(), 'pages' => $entries->lastPage()],
            'can_manage' => $viewer->hasPermission('billing.manage'),
        ]);
    }

    /** Balanslar / qarzdorlar ro'yxati. */
    public function balances(Request $request): JsonResponse
    {
        $data = $this->listFilters($request);
        $page = $this->filtered($data)
            ->select(['users.id', 'users.first_name', 'users.last_name', 'users.phone', 'users.active'])
            ->selectRaw('coalesce(b.balance, 0) as balance, b.last_payment_date')
            ->tap(fn ($q) => $this->sorted($q, $data['sort'] ?? 'balance'))
            ->paginate($data['per_page'] ?? $this->settings->int('ui.page_size'));

        $groups = $this->groupNames($page->getCollection()->pluck('id')->all());
        $sum = $this->filtered($data)->selectRaw('count(*) as students,
            sum(case when coalesce(b.balance, 0) < 0 then 1 else 0 end) as debtors,
            sum(case when coalesce(b.balance, 0) < 0 then -b.balance else 0 end) as total_debt,
            sum(case when coalesce(b.balance, 0) > 0 then b.balance else 0 end) as total_credit')->first();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => [
                'student_id' => (int) $r->id, 'first_name' => $r->first_name, 'last_name' => $r->last_name, 'phone' => $r->phone,
                'active' => (bool) $r->active, 'balance' => (int) $r->balance,
                'last_payment_date' => $r->last_payment_date ? substr((string) $r->last_payment_date, 0, 10) : null,
                'groups' => $groups[$r->id] ?? [],
            ])->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'pages' => $page->lastPage()],
            'summary' => [
                'students' => (int) $sum->students, 'debtors' => (int) $sum->debtors,
                'total_debt' => (int) $sum->total_debt, 'total_credit' => (int) $sum->total_credit,
            ],
        ]);
    }

    /** Balanslar CSV (Excel: UTF-8 BOM, ; ajratuvchi). */
    public function balancesCsv(Request $request): StreamedResponse
    {
        $data = $this->listFilters($request);
        $rows = $this->filtered($data)
            ->select(['users.id', 'users.first_name', 'users.last_name', 'users.phone'])
            ->selectRaw('coalesce(b.balance, 0) as balance, b.last_payment_date')
            ->tap(fn ($q) => $this->sorted($q, $data['sort'] ?? 'balance'))
            ->get();
        $groups = $this->groupNames($rows->pluck('id')->all());

        return $this->csv('balanslar_'.now()->format('Ymd').'.csv',
            ["O'quvchi", 'Telefon', 'Guruhlar', 'Balans', 'Qarz', "Oxirgi to'lov"],
            $rows->map(fn ($r) => [
                trim($r->first_name.' '.$r->last_name), (string) $r->phone, implode(', ', $groups[$r->id] ?? []),
                (int) $r->balance, max(0, -(int) $r->balance), $r->last_payment_date ? substr((string) $r->last_payment_date, 0, 10) : '',
            ]));
    }

    /** Davr bo'yicha tushum va qarz hisoboti. */
    public function report(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $base = fn () => DB::table('billing_entries')->whereBetween('entry_date', [$from, $to]);

        $payments = $base()->where('type', BillingEntry::PAYMENT)->whereNull('voided_at');
        $byMethod = (clone $payments)->groupBy('method')->selectRaw('method, count(*) as c, sum(amount) as s')->get()
            ->map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->c, 'amount' => (int) $r->s])->sortByDesc('amount')->values();
        $byDay = (clone $payments)->groupBy('entry_date')->selectRaw('entry_date, count(*) as c, sum(amount) as s')->orderBy('entry_date')->get()
            ->map(fn ($r) => ['date' => substr((string) $r->entry_date, 0, 10), 'count' => (int) $r->c, 'amount' => (int) $r->s]);
        $voided = $base()->where('type', BillingEntry::PAYMENT)->whereNotNull('voided_at')->selectRaw('count(*) as c, coalesce(sum(amount), 0) as s')->first();

        $debt = DB::query()->fromSub($this->billing->balancesQuery()->selectRaw('coalesce(b.balance, 0) as balance'), 'x')
            ->selectRaw('sum(case when balance < 0 then 1 else 0 end) as debtors, sum(case when balance < 0 then -balance else 0 end) as total_debt')->first();

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'income' => (int) (clone $payments)->sum('amount'),
            'payments_count' => (int) (clone $payments)->count(),
            'charged' => -(int) $base()->where('type', BillingEntry::CHARGE)->whereNull('voided_at')->sum('amount'),
            'adjusted' => (int) $base()->where('type', BillingEntry::ADJUSTMENT)->whereNull('voided_at')->sum('amount'),
            'voided' => ['count' => (int) $voided->c, 'amount' => (int) $voided->s],
            'by_method' => $byMethod,
            'by_day' => $byDay,
            'debtors' => (int) $debt->debtors,
            'total_debt' => (int) $debt->total_debt,
        ]);
    }

    /** Davrdagi to'lov va tuzatishlar CSV (bekor qilinganlar ham — belgisi bilan). */
    public function entriesCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->period($request);
        $methods = (array) $this->settings->get('billing.method_labels');
        $types = (array) $this->settings->get('billing.type_labels');
        $rows = DB::table('billing_entries')
            ->join('users as s', 's.id', '=', 'billing_entries.student_id')
            ->leftJoin('users as c', 'c.id', '=', 'billing_entries.created_by')
            ->whereIn('billing_entries.type', [BillingEntry::PAYMENT, BillingEntry::ADJUSTMENT])
            ->whereBetween('billing_entries.entry_date', [$from, $to])
            ->orderBy('billing_entries.entry_date')->orderBy('billing_entries.id')
            ->get(['billing_entries.*', 's.first_name', 's.last_name', 's.phone', 'c.first_name as c_first', 'c.last_name as c_last']);

        return $this->csv('tolovlar_'.str_replace('-', '', $from).'_'.str_replace('-', '', $to).'.csv',
            ['Sana', "O'quvchi", 'Telefon', 'Tur', 'Summa', 'Usul', 'Izoh', 'Bekor qilingan', 'Bekor qilish sababi', 'Kiritdi'],
            $rows->map(fn ($r) => [
                substr((string) $r->entry_date, 0, 10), trim($r->first_name.' '.$r->last_name), (string) $r->phone,
                $types[$r->type] ?? $r->type, (int) $r->amount, $r->method ? ($methods[$r->method] ?? $r->method) : '',
                (string) $r->note, $r->voided_at ? 'ha' : '', (string) $r->void_reason, trim($r->c_first.' '.$r->c_last),
            ]));
    }

    public function storePayment(Request $request, Notifier $notifier, User $student): JsonResponse
    {
        abort_unless($student->role === Role::Student, 422, "To'lov faqat o'quvchiga yoziladi.");
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:'.$this->settings->int('billing.max_payment')],
            'method' => ['required', 'string', 'in:'.implode(',', array_keys((array) $this->settings->get('billing.method_labels')))],
            'date' => $this->dateRules(),
            'note' => ['nullable', 'string', 'max:500'],
            'request_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        $key = isset($data['request_id']) ? 'p:'.$data['request_id'] : null;
        if ($key && ($existing = BillingEntry::where('dedupe_key', $key)->first())) {
            return $this->duplicate($existing, $student);
        }
        try {
            $entry = BillingEntry::create([
                'student_id' => $student->id, 'type' => BillingEntry::PAYMENT, 'amount' => $data['amount'],
                'entry_date' => $data['date'], 'method' => $data['method'], 'note' => $data['note'] ?? null,
                'dedupe_key' => $key, 'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate(BillingEntry::where('dedupe_key', $key)->firstOrFail(), $student);
        }
        Audit::log('billing.payment', 'billing_entry', $entry->id, ['student_id' => $student->id, 'amount' => $entry->amount, 'method' => $entry->method, 'date' => $data['date']]);
        $balance = $this->billing->balance($student->id);

        if ($this->settings->bool('billing.enabled') && $this->settings->bool('billing.notify_payment')) {
            defer(fn () => $this->notifyPayment($notifier, $student, $entry, $balance));
        }

        return response()->json(['entry' => $entry->fresh()->toApi(), 'balance' => $balance], 201);
    }

    /** Qo'lda tuzatish/chegirma: musbat — balansni oshiradi, manfiy — kamaytiradi. Sabab majburiy. */
    public function storeAdjustment(Request $request, User $student): JsonResponse
    {
        abort_unless($student->role === Role::Student, 422, "Tuzatish faqat o'quvchiga yoziladi.");
        $max = $this->settings->int('billing.max_payment');
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'min:-'.$max, 'max:'.$max],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'date' => $this->dateRules(),
            'request_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
        // Ikki marta bosish / qayta yuborish ikkinchi tuzatish yaratmaydi (to'lovdagi kabi).
        $key = isset($data['request_id']) ? 'a:'.$data['request_id'] : null;
        if ($key && ($existing = BillingEntry::where('dedupe_key', $key)->first())) {
            return $this->duplicate($existing, $student);
        }
        try {
            $entry = BillingEntry::create([
                'student_id' => $student->id, 'type' => BillingEntry::ADJUSTMENT, 'amount' => $data['amount'],
                'entry_date' => $data['date'], 'note' => $data['reason'], 'dedupe_key' => $key, 'created_by' => $request->user()->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicate(BillingEntry::where('dedupe_key', $key)->firstOrFail(), $student);
        }
        Audit::log('billing.adjustment', 'billing_entry', $entry->id, ['student_id' => $student->id, 'amount' => $entry->amount, 'reason' => $data['reason']]);

        return response()->json(['entry' => $entry->fresh()->toApi(), 'balance' => $this->billing->balance($student->id)], 201);
    }

    /** To'lov yoki tuzatishni bekor qilish (o'chirilmaydi). Hisoblangan kunlar bekor qilinmaydi — tuzatish bilan qoplanadi. */
    public function void(Request $request, BillingEntry $entry): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $entry = DB::transaction(function () use ($entry, $data, $request) {
            $e = BillingEntry::lockForUpdate()->findOrFail($entry->id);
            abort_if($e->type === BillingEntry::CHARGE, 422, "Kunlik hisoblashni bekor qilib bo'lmaydi — tuzatish kiriting.");
            abort_if($e->voided_at !== null, 422, 'Bu yozuv allaqachon bekor qilingan.');
            $e->forceFill(['voided_at' => now(), 'voided_by' => $request->user()->id, 'void_reason' => $data['reason']])->save();

            return $e;
        });
        Audit::log('billing.void', 'billing_entry', $entry->id, ['student_id' => $entry->student_id, 'amount' => $entry->amount, 'reason' => $data['reason']]);

        return response()->json(['entry' => $entry->toApi(), 'balance' => $this->billing->balance($entry->student_id)]);
    }

    // ── Yordamchilar ─────────────────────────────────────────

    private function summary(User $student): array
    {
        $balance = $this->billing->balance($student->id);
        $last = BillingEntry::where('student_id', $student->id)->where('type', BillingEntry::PAYMENT)->whereNull('voided_at')
            ->orderByDesc('entry_date')->orderByDesc('id')->first();
        $recent = BillingEntry::with('group:id,name')->where('student_id', $student->id)->where('type', '!=', BillingEntry::CHARGE)
            ->orderByDesc('entry_date')->orderByDesc('id')->limit($this->settings->int('billing.history_limit'))->get();

        return [
            'student' => ['id' => $student->id, 'first_name' => $student->first_name, 'last_name' => $student->last_name, 'phone' => $student->phone],
            'balance' => $balance,
            'debt' => max(0, -$balance),
            'month_charged' => -(int) BillingEntry::where('student_id', $student->id)->where('type', BillingEntry::CHARGE)->whereNull('voided_at')
                ->where('entry_date', '>=', now()->startOfMonth()->toDateString())->sum('amount'),
            'last_payment' => $last ? ['amount' => $last->amount, 'date' => $last->entry_date->toDateString(), 'method' => $last->method] : null,
            'recent' => $recent->map->toApi()->all(),
        ];
    }

    private function duplicate(BillingEntry $existing, User $student): JsonResponse
    {
        abort_unless($existing->student_id === $student->id && in_array($existing->type, [BillingEntry::PAYMENT, BillingEntry::ADJUSTMENT], true), 409, "So'rov kaliti boshqa yozuvga tegishli.");

        return response()->json(['entry' => $existing->toApi(), 'balance' => $this->billing->balance($student->id), 'duplicate' => true]);
    }

    private function dateRules(): array
    {
        $today = now()->toDateString();

        return ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today,
            'after_or_equal:'.now()->subDays($this->settings->int('billing.max_backdate_days'))->toDateString()];
    }

    private function listFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:all,debt,credit,zero'],
            'group_id' => ['nullable', 'integer'],
            'enrolled' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:balance,-balance,name,last_payment'],
            'per_page' => ['nullable', 'integer', 'between:1,500'],
        ]);
    }

    private function filtered(array $data): Builder
    {
        $active = fn ($q) => $q->from('group_members')->select('student_id')->whereNull('left_at');

        return $this->billing->balancesQuery()
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('users.phone', 'like', "%$s%")
                ->orWhere('users.first_name', 'like', "%$s%")->orWhere('users.last_name', 'like', "%$s%")))
            ->when(($data['status'] ?? 'all') === 'debt', fn ($q) => $q->whereRaw('coalesce(b.balance, 0) < 0'))
            ->when(($data['status'] ?? 'all') === 'credit', fn ($q) => $q->whereRaw('coalesce(b.balance, 0) > 0'))
            ->when(($data['status'] ?? 'all') === 'zero', fn ($q) => $q->whereRaw('coalesce(b.balance, 0) = 0'))
            ->when($data['group_id'] ?? null, fn ($q, $g) => $q->whereIn('users.id', fn ($s) => $active($s)->where('group_id', $g)))
            ->when((bool) ($data['enrolled'] ?? false), fn ($q) => $q->whereIn('users.id', $active));
    }

    private function sorted(Builder $q, string $sort): void
    {
        match ($sort) {
            'name' => $q->orderBy('users.first_name')->orderBy('users.last_name'),
            '-balance' => $q->orderByRaw('coalesce(b.balance, 0) desc'),
            'last_payment' => $q->orderByRaw('b.last_payment_date is null')->orderBy('b.last_payment_date'),
            default => $q->orderByRaw('coalesce(b.balance, 0) asc'),
        };
        $q->orderBy('users.id');
    }

    /** @return array<int, list<string>> */
    private function groupNames(array $studentIds): array
    {
        return DB::table('group_members')->join('study_groups', 'study_groups.id', '=', 'group_members.group_id')
            ->whereIn('group_members.student_id', $studentIds)->whereNull('group_members.left_at')
            ->orderBy('study_groups.name')->get(['group_members.student_id', 'study_groups.name'])
            ->groupBy('student_id')->map(fn ($c) => $c->pluck('name')->all())->all();
    }

    /** @return array{0:string,1:string} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfMonth();
        abort_if($from->greaterThan($to), 422, "Davr boshi oxiridan keyin bo'lmasin.");
        abort_if($from->diffInDays($to) > $this->settings->int('reports.max_days'), 422, 'Davr juda katta.');

        return [$from->toDateString(), $to->toDateString()];
    }

    private function csv(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_string($v) ? Csv::cell($v) : $v, $row), ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function notifyPayment(Notifier $notifier, User $student, BillingEntry $entry, int $balance): void
    {
        $vars = [
            'amount' => $this->billing->money($entry->amount),
            'method' => ((array) $this->settings->get('billing.method_labels'))[$entry->method] ?? (string) $entry->method,
            'date' => $entry->entry_date->format('d.m.Y'),
            'balance' => $this->billing->money($balance),
        ];
        $kind = 'billing.payment:'.$entry->id;
        $notifier->sendTemplate($student->id, $kind, 'telegram.text.billing_payment', $vars + ['name' => $student->first_name]);
    }
}
