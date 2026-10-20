<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\BillingEntry;
use App\Models\Course;
use App\Models\User;
use App\Support\Billing;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** To'lovlar: kunlik hisoblash, narx tarixi, to'lov/bekor qilish, huquqlar, CSV. */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private int $courseId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 09:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->student = User::factory()->create(['first_name' => 'Aziz', 'last_name' => 'K']);
        $this->courseId = Course::create(['title' => 'K', 'created_by' => $this->admin->id])->id;
    }

    private function group(array $attrs = []): int
    {
        $teacher = User::factory()->role(Role::Teacher)->create();
        $row = $attrs + ['name' => 'G', 'course_id' => $this->courseId, 'teacher_id' => $teacher->id, 'start_date' => '2026-01-01',
            'active' => true, 'created_at' => now(), 'updated_at' => now()];
        // Filiallar olib tashlanayotgan bo'lishi mumkin — ustun bo'lsa to'ldiriladi.
        if (Schema::hasColumn('study_groups', 'branch_id')) {
            $row['branch_id'] = DB::table('branches')->insertGetId(['name' => 'B', 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::table('study_groups')->insertGetId($row);
    }

    private function join(int $groupId, User $student, string $joined = '2026-01-01 10:00:00', ?string $left = null): void
    {
        DB::table('group_members')->insert(['group_id' => $groupId, 'student_id' => $student->id, 'joined_at' => $joined, 'left_at' => $left]);
    }

    private function charges(User $student, ?int $groupId = null): \Illuminate\Support\Collection
    {
        return BillingEntry::where('student_id', $student->id)->where('type', 'CHARGE')
            ->when($groupId, fn ($q) => $q->where('group_id', $groupId))->orderBy('entry_date')->get();
    }

    private function accrue(string $from, string $to): array
    {
        return app(Billing::class)->accrue(Carbon::parse($from), Carbon::parse($to));
    }

    // ── Hisoblash matematikasi ───────────────────────────────

    public function test_daily_charges_sum_exactly_to_monthly_fee_for_every_month_length(): void
    {
        foreach ([['2026-10', 31], ['2026-11', 30], ['2027-02', 28], ['2028-02', 29]] as [$month, $days]) {
            foreach ([400000, 333333, 1, 0, 999999] as $fee) {
                $sum = 0;
                for ($d = 1; $d <= $days; $d++) {
                    $c = Billing::dailyCharge($fee, Carbon::parse(sprintf('%s-%02d', $month, $d)));
                    $this->assertGreaterThanOrEqual(0, $c);
                    $sum += $c;
                }
                $this->assertSame($fee, $sum, "$month, $fee");
            }
        }
        $this->assertSame(12903, Billing::dailyCharge(400000, Carbon::parse('2026-10-01')));
        $this->assertSame(400000 - 12903 * 30, Billing::dailyCharge(400000, Carbon::parse('2026-10-31')));
        $this->assertSame(13333, Billing::dailyCharge(400000, Carbon::parse('2026-11-05')));
        $this->assertSame(14285, Billing::dailyCharge(400000, Carbon::parse('2027-02-05')));
        $this->assertSame(13796, Billing::dailyCharge(400000, Carbon::parse('2028-02-29'))); // 400000 - 13793*28
    }

    public function test_full_month_accrual_per_membership_equals_fee(): void
    {
        Carbon::setTestNow('2028-03-01 08:00:00');
        $g1 = $this->group(['name' => 'G1']);
        $g2 = $this->group(['name' => 'G2']);
        $this->join($g1, $this->student);
        $this->join($g2, $this->student);

        foreach ([['2026-10-01', '2026-10-31'], ['2026-11-01', '2026-11-30'], ['2027-02-01', '2027-02-28'], ['2028-02-01', '2028-02-29']] as [$from, $to]) {
            $this->accrue($from, $to);
            foreach ([$g1, $g2] as $g) {
                $sum = BillingEntry::where(['student_id' => $this->student->id, 'group_id' => $g])->whereBetween('entry_date', [$from, $to])->sum('amount');
                $this->assertSame(-400000, (int) $sum, "$from $g");
            }
        }
        // Ikki guruh (ikki kurs) — ikki barobar.
        $this->assertSame(-400000 * 2 * 4, app(Billing::class)->balance($this->student->id));
    }

    // ── Idempotentlik va o'tkazib yuborilgan kunlar ──────────

    public function test_accrual_is_idempotent_and_catches_up_missed_days_within_lookback(): void
    {
        app(Settings::class)->set(['billing.accrual_start_date' => '2026-10-01', 'billing.catchup_days' => 7], null);
        $g = $this->group();
        $this->join($g, $this->student);

        $this->artisan('tisu:billing-accrue')->assertSuccessful();
        $this->assertSame(['2026-10-14', '2026-10-20'], [$this->charges($this->student)->first()->entry_date->toDateString(), $this->charges($this->student)->last()->entry_date->toDateString()]);
        $this->assertCount(7, $this->charges($this->student));

        $this->artisan('tisu:billing-accrue')->assertSuccessful();
        $this->accrue('2026-10-14', '2026-10-20');
        $this->assertCount(7, $this->charges($this->student), 'Takroriy ishga tushirish dublikat yaratmasin');

        // Server 3 kun ishlamadi: 21..23 to'ldiriladi.
        Carbon::setTestNow('2026-10-23 00:20:00');
        $this->artisan('tisu:billing-accrue')->assertSuccessful();
        $this->assertCount(10, $this->charges($this->student));

        // Qo'lda: boshlanish sanasidan oldin hisoblanmaydi.
        $this->artisan('tisu:billing-accrue', ['--from' => '2026-09-01'])->assertSuccessful();
        $this->assertCount(23, $this->charges($this->student));
        $this->assertSame('2026-10-01', $this->charges($this->student)->first()->entry_date->toDateString());
    }

    public function test_dry_run_writes_nothing_and_future_dates_rejected(): void
    {
        app(Settings::class)->set(['billing.accrual_start_date' => '2026-10-15'], null);
        $this->join($this->group(), $this->student);
        $this->artisan('tisu:billing-accrue', ['--dry-run' => true])->expectsOutputToContain('6 yangi yozuv')->assertSuccessful();
        $this->assertCount(0, $this->charges($this->student));
        $this->artisan('tisu:billing-accrue', ['--date' => '2026-10-21'])->assertFailed();
        $this->artisan('tisu:billing-accrue', ['--date' => '2026-13-01'])->assertFailed();
    }

    public function test_first_run_without_start_date_starts_today_and_disabled_does_nothing(): void
    {
        $this->join($this->group(), $this->student);
        app(Settings::class)->set(['billing.enabled' => false], null);
        $this->artisan('tisu:billing-accrue')->assertSuccessful();
        $this->assertCount(0, $this->charges($this->student));

        app(Settings::class)->set(['billing.enabled' => true], null);
        $this->artisan('tisu:billing-accrue')->assertSuccessful();
        $this->assertSame('2026-10-20', app(Settings::class)->get('billing.accrual_start_date'));
        $this->assertSame(['2026-10-20'], $this->charges($this->student)->map(fn ($c) => $c->entry_date->toDateString())->all());
    }

    // ── Narx o'zgarishi ──────────────────────────────────────

    public function test_price_change_applies_from_change_date_and_is_not_retroactive(): void
    {
        Carbon::setTestNow('2026-10-05 08:00:00');
        $g = $this->group();
        $this->join($g, $this->student);
        $this->accrue('2026-10-01', '2026-10-05');
        $old = $this->charges($this->student)->pluck('amount', 'id')->all();

        // 10-oktabr kuni narx o'zgaradi; 6..9 kunlar hali hisoblanmagan (server ishlamagan).
        Carbon::setTestNow('2026-10-10 14:00:00');
        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());
        $this->patchJson('/v1/settings', ['values' => ['billing.monthly_fee' => 310000]])->assertOk();

        Carbon::setTestNow('2026-10-31 08:00:00');
        $this->accrue('2026-10-06', '2026-10-31');

        $byDay = $this->charges($this->student)->mapWithKeys(fn ($c) => [$c->entry_date->toDateString() => -$c->amount]);
        $this->assertSame($old, BillingEntry::whereIn('id', array_keys($old))->pluck('amount', 'id')->all(), "Eski yozuvlar o'zgarmasin");
        $this->assertSame(12903, $byDay['2026-10-09'], "O'zgarishdan oldingi kun (kechikib hisoblangan) — eski narx");
        $this->assertSame(10000, $byDay['2026-10-10']);
        $this->assertSame(10000, $byDay['2026-10-31']); // 310000 - 10000*30
        $this->assertSame(12903 * 9 + 10000 * 22, $byDay->sum());

        // Narx yana o'zgarib, keyin default'ga qaytarilsa ham tarix saqlanadi.
        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->deleteJson('/v1/settings/billing.monthly_fee')->assertOk();
        $billing = new Billing(app(Settings::class));
        $this->assertSame(400000, $billing->feeOn('2026-10-09'));
        $this->assertSame(310000, $billing->feeOn('2026-11-01'));
        $this->assertSame(400000, $billing->feeOn('2026-11-02'));
    }

    // ── Kim hisoblanmaydi ────────────────────────────────────

    public function test_left_inactive_and_out_of_range_memberships_are_not_charged(): void
    {
        $s = fn () => User::factory()->create();
        [$left, $inactive, $late, $ended, $sameDay, $joinedLate, $teacher] = [$s(), $s(), $s(), $s(), $s(), $s(), User::factory()->role(Role::Teacher)->create()];
        $g = $this->group();
        $this->join($g, $left, '2026-10-01 10:00:00', '2026-10-05 12:00:00');
        $this->join($g, $sameDay, '2026-10-03 10:00:00', '2026-10-03 10:05:00');
        $this->join($g, $joinedLate, '2026-10-18 15:00:00');
        $this->join($g, $teacher); // o'quvchi emas
        $this->join($this->group(['active' => false]), $inactive);
        $this->join($this->group(['start_date' => '2026-10-15']), $late);
        $this->join($this->group(['start_date' => '2026-09-01', 'end_date' => '2026-10-10']), $ended);

        $this->accrue('2026-10-01', '2026-10-20');

        $days = fn (User $u) => $this->charges($u)->map(fn ($c) => $c->entry_date->format('d'))->all();
        $this->assertSame(['01', '02', '03', '04', '05'], $days($left), 'Chiqib ketgan kuni ham hisoblanadi, keyin — yo\'q');
        $this->assertSame([], $days($sameDay));
        $this->assertSame(['18', '19', '20'], $days($joinedLate));
        $this->assertSame([], $days($teacher));
        $this->assertSame([], $days($inactive));
        $this->assertCount(6, $days($late));
        $this->assertSame('10', last($days($ended)));
        $this->assertCount(10, $days($ended));
    }

    // ── To'lovlar, bekor qilish, tuzatish ────────────────────

    public function test_payment_updates_balance_notifies_and_is_idempotent_by_request_id(): void
    {
        $parent = User::factory()->role(Role::Parent)->create();
        DB::table('parent_child')->insert(['parent_id' => $parent->id, 'child_id' => $this->student->id]);
        $g = $this->group();
        $this->join($g, $this->student);
        $this->accrue('2026-10-01', '2026-10-20');
        $charged = 12903 * 20;

        Sanctum::actingAs($this->admin);
        $payload = ['amount' => 400000, 'method' => 'CASH', 'date' => '2026-10-20', 'note' => 'Oktabr', 'request_id' => 'abc-123'];
        $r = $this->postJson("/v1/billing/students/{$this->student->id}/payments", $payload)->assertCreated();
        $this->assertSame(400000 - $charged, $r->json('balance'));
        $this->postJson("/v1/billing/students/{$this->student->id}/payments", $payload)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, BillingEntry::where('type', 'PAYMENT')->count());

        $this->assertDatabaseHas('notifications_log', ['user_id' => $this->student->id, 'kind' => 'billing.payment:'.$r->json('entry.id')]);
        $this->assertDatabaseHas('notifications_log', ['user_id' => $parent->id, 'kind' => 'billing.payment:'.$r->json('entry.id')]);
        $text = json_decode(DB::table('notifications_log')->where('user_id', $this->student->id)->value('payload'), true)['text'];
        $this->assertStringContainsString("400 000 so'm", $text);
        $this->assertStringContainsString('Naqd', $text);

        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.payment', 'entity_id' => $r->json('entry.id')]);

        // Validatsiya: kelajak sana, noma'lum usul, juda eski sana, nol summa, o'quvchi emas.
        $url = "/v1/billing/students/{$this->student->id}/payments";
        $this->postJson($url, ['amount' => 1000, 'method' => 'CASH', 'date' => '2026-10-21'])->assertUnprocessable();
        $this->postJson($url, ['amount' => 1000, 'method' => 'BITCOIN', 'date' => '2026-10-20'])->assertUnprocessable();
        $this->postJson($url, ['amount' => 1000, 'method' => 'CARD', 'date' => '2026-08-01'])->assertUnprocessable();
        $this->postJson($url, ['amount' => 0, 'method' => 'CARD', 'date' => '2026-10-20'])->assertUnprocessable();
        $this->postJson($url, ['amount' => 1.5, 'method' => 'CARD', 'date' => '2026-10-20'])->assertUnprocessable();
        $this->postJson("/v1/billing/students/{$parent->id}/payments", ['amount' => 1000, 'method' => 'CARD', 'date' => '2026-10-20'])->assertUnprocessable();
    }

    public function test_void_keeps_entry_and_excludes_it_from_balance(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson("/v1/billing/students/{$this->student->id}/payments", ['amount' => 150000, 'method' => 'CARD', 'date' => '2026-10-19'])
            ->assertCreated()->json('entry.id');

        $this->postJson("/v1/billing/entries/$id/void", [])->assertUnprocessable();
        $this->postJson("/v1/billing/entries/$id/void", ['reason' => "Noto'g'ri summa"])->assertOk()
            ->assertJsonPath('balance', 0)->assertJsonPath('entry.voided', true);
        $this->postJson("/v1/billing/entries/$id/void", ['reason' => 'Yana'])->assertUnprocessable();
        $this->assertDatabaseHas('billing_entries', ['id' => $id, 'amount' => 150000, 'void_reason' => "Noto'g'ri summa", 'voided_by' => $this->admin->id]);

        $this->join($this->group(), $this->student);
        $this->accrue('2026-10-20', '2026-10-20');
        $charge = $this->charges($this->student)->first();
        $this->postJson("/v1/billing/entries/{$charge->id}/void", ['reason' => 'Kerak emas'])->assertUnprocessable();

        // Tuzatish (chegirma) — sabab majburiy, bekor qilsa bo'ladi.
        $url = "/v1/billing/students/{$this->student->id}/adjustments";
        $this->postJson($url, ['amount' => 50000, 'date' => '2026-10-20'])->assertUnprocessable();
        $this->postJson($url, ['amount' => 0, 'reason' => 'Chegirma', 'date' => '2026-10-20'])->assertUnprocessable();
        $adj = $this->postJson($url, ['amount' => 50000, 'reason' => 'Aka-uka chegirmasi', 'date' => '2026-10-20'])->assertCreated();
        $this->assertSame(50000 - 12903, $adj->json('balance'));
        $this->postJson("/v1/billing/entries/{$adj->json('entry.id')}/void", ['reason' => 'Xato'])->assertOk()->assertJsonPath('balance', -12903);

        $show = $this->getJson("/v1/billing/students/{$this->student->id}")->assertOk();
        $this->assertSame(-12903, $show->json('balance'));
        $this->assertSame(12903, $show->json('debt'));
        $this->assertCount(3, $show->json('entries'));
        $this->assertSame(['charged' => 12903, 'paid' => 0, 'adjusted' => 0], $show->json('totals'));
        $this->getJson("/v1/billing/students/{$this->student->id}?type=MANUAL")->assertJsonCount(2, 'entries');
    }

    // ── Huquqlar ─────────────────────────────────────────────

    public function test_permissions_student_parent_teacher(): void
    {
        $other = User::factory()->create();
        $parent = User::factory()->role(Role::Parent)->create();
        DB::table('parent_child')->insert(['parent_id' => $parent->id, 'child_id' => $this->student->id]);
        $teacher = User::factory()->role(Role::Teacher)->create();

        Sanctum::actingAs($this->student);
        $this->getJson('/v1/billing/me')->assertOk()->assertJsonCount(1, 'students')->assertJsonPath('students.0.student.id', $this->student->id);
        $this->getJson("/v1/billing/students/{$this->student->id}")->assertOk()->assertJsonPath('can_manage', false);
        $this->getJson("/v1/billing/students/{$other->id}")->assertForbidden();
        $this->getJson('/v1/billing/balances')->assertForbidden();
        $this->postJson("/v1/billing/students/{$this->student->id}/payments", ['amount' => 1000, 'method' => 'CASH', 'date' => '2026-10-20'])->assertForbidden();

        Sanctum::actingAs($parent);
        $this->getJson('/v1/billing/me')->assertOk()->assertJsonPath('students.0.student.id', $this->student->id);
        $this->getJson("/v1/billing/students/{$this->student->id}")->assertOk();
        $this->getJson("/v1/billing/students/{$other->id}")->assertForbidden();

        Sanctum::actingAs($teacher);
        $this->getJson('/v1/billing/me')->assertOk()->assertJsonCount(0, 'students');
        $this->getJson("/v1/billing/students/{$this->student->id}")->assertForbidden();
        $this->postJson("/v1/billing/students/{$this->student->id}/payments", ['amount' => 1000, 'method' => 'CASH', 'date' => '2026-10-20'])->assertForbidden();
        $this->getJson('/v1/billing/report')->assertForbidden();
        $this->getJson('/v1/billing/balances.csv')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->assertContains('billing.manage', $this->admin->toApi()['permissions']);
        $this->getJson('/v1/billing/balances')->assertOk();
    }

    // ── Ro'yxat, hisobot, CSV ────────────────────────────────

    public function test_balances_list_filters_summary_and_report(): void
    {
        $rich = User::factory()->create(['first_name' => 'Boy']);
        $g = $this->group(['name' => 'ENG-1']);
        $this->join($g, $this->student);
        $this->join($g, $rich);
        $this->accrue('2026-10-01', '2026-10-10');
        Sanctum::actingAs($this->admin);
        $this->postJson("/v1/billing/students/{$rich->id}/payments", ['amount' => 500000, 'method' => 'CARD', 'date' => '2026-10-05'])->assertCreated();
        $this->postJson("/v1/billing/students/{$this->student->id}/payments", ['amount' => 20000, 'method' => 'CASH', 'date' => '2026-10-06'])->assertCreated();
        $voided = $this->postJson("/v1/billing/students/{$this->student->id}/payments", ['amount' => 7000, 'method' => 'CASH', 'date' => '2026-10-06'])->json('entry.id');
        $this->postJson("/v1/billing/entries/$voided/void", ['reason' => 'Xato'])->assertOk();

        $r = $this->getJson('/v1/billing/balances?status=debt')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $this->student->id)->assertJsonPath('data.0.groups.0', 'ENG-1')
            ->assertJsonPath('data.0.last_payment_date', '2026-10-06');
        $this->assertSame(20000 - 129030, $r->json('data.0.balance'));
        $this->assertSame(['students' => 1, 'debtors' => 1, 'total_debt' => 109030, 'total_credit' => 0], $r->json('summary'));
        $this->getJson('/v1/billing/balances?status=credit')->assertJsonCount(1, 'data')->assertJsonPath('data.0.student_id', $rich->id);
        $this->getJson('/v1/billing/balances?q=Boy')->assertJsonCount(1, 'data');
        $this->getJson('/v1/billing/balances?sort=-balance')->assertJsonPath('data.0.student_id', $rich->id);
        $this->getJson("/v1/billing/balances?group_id=$g")->assertJsonPath('meta.total', 2);
        $this->getJson('/v1/billing/balances?enrolled=1')->assertJsonPath('meta.total', 2);

        $rep = $this->getJson('/v1/billing/report?from=2026-10-01&to=2026-10-31')->assertOk();
        $this->assertSame(520000, $rep->json('income'));
        $this->assertSame(2, $rep->json('payments_count'));
        $this->assertSame(129030 * 2, $rep->json('charged'));
        $this->assertSame(['count' => 1, 'amount' => 7000], $rep->json('voided'));
        $this->assertSame('CARD', $rep->json('by_method.0.method'));
        $this->assertSame(1, $rep->json('debtors'));
        $this->getJson('/v1/billing/report?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
    }

    public function test_csv_exports_escape_formula_cells(): void
    {
        $evil = User::factory()->create(['first_name' => '=HYPERLINK("http://x","y")', 'last_name' => null, 'phone' => '+998901112233']);
        Sanctum::actingAs($this->admin);
        $this->postJson("/v1/billing/students/{$evil->id}/payments", ['amount' => 1000, 'method' => 'CASH', 'date' => '2026-10-20', 'note' => '@SUM(A1)'])->assertCreated();
        $this->postJson("/v1/billing/students/{$evil->id}/adjustments", ['amount' => -500, 'reason' => '-2+3', 'date' => '2026-10-20'])->assertCreated();

        $csv = $this->get('/v1/billing/balances.csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('+998901112233', $csv);
        $this->assertStringNotContainsString("'+998", $csv);

        $csv = $this->get('/v1/billing/entries.csv?from=2026-10-01&to=2026-10-31')->assertOk()->streamedContent();
        $this->assertStringContainsString("'@SUM(A1)", $csv);
        $this->assertStringContainsString("'-2+3", $csv);
        $this->assertStringContainsString(';-500;', $csv);
    }

    public function test_settings_validation_and_default_role_permission(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());
        $this->patchJson('/v1/settings', ['values' => ['billing.accrual_start_date' => '2026-02-30']])->assertUnprocessable();
        $this->patchJson('/v1/settings', ['values' => ['billing.accrual_start_date' => '2026-10-01']])->assertOk();
        $this->patchJson('/v1/settings', ['values' => ['billing.accrual_start_date' => '']])->assertOk();
        $this->patchJson('/v1/settings', ['values' => ['billing.monthly_fee' => -1]])->assertUnprocessable();
        $groups = $this->getJson('/v1/settings')->assertOk()->json('groups');
        $this->assertArrayHasKey('billing', $groups);
        $this->assertTrue($this->admin->hasPermission('billing.manage'));
        $this->assertFalse(User::factory()->role(Role::Teacher)->create()->hasPermission('billing.manage'));
    }

    // ── Telegram ─────────────────────────────────────────────

    public function test_bot_balance_and_debt_reminder(): void
    {
        config(['services.telegram.bot_token' => '1:T', 'services.telegram.webhook_secret' => 'webhook-secret-for-tests-0123456789abcdef']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $parent = User::factory()->role(Role::Parent)->create();
        DB::table('parent_child')->insert(['parent_id' => $parent->id, 'child_id' => $this->student->id]);
        foreach ([[$this->student, 102], [$parent, 103]] as [$u, $tg]) {
            DB::table('telegram_links')->insert(['user_id' => $u->id, 'telegram_id' => $tg, 'chat_id' => $tg, 'linked_at' => now()]);
        }
        $this->join($this->group(), $this->student);
        $this->accrue('2026-10-01', '2026-10-20');
        BillingEntry::create(['student_id' => $this->student->id, 'type' => 'PAYMENT', 'amount' => 100000, 'method' => 'CARD', 'entry_date' => '2026-10-02']);

        $this->postJson('/v1/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 1, 'text' => '💰 Balans', 'chat' => ['id' => 103, 'type' => 'private'], 'from' => ['id' => 103, 'first_name' => 'X'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret-for-tests-0123456789abcdef'])->assertOk();
        $sent = collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_ends_with($r->url(), 'sendMessage'))->last();
        $this->assertStringContainsString('Aziz K', $sent['text']);
        $this->assertStringContainsString("-158 060 so'm", $sent['text']); // 100000 - 12903*20
        $this->assertStringContainsString('Karta', $sent['text']);

        // Qarz eslatmasi: o'chiq — hech narsa; yoqilgan — o'quvchi va ota-onaga, bir davrda bir marta.
        $this->artisan('tisu:billing-remind-debts')->assertSuccessful();
        $this->assertSame(0, DB::table('notifications_log')->where('kind', 'like', 'billing.debt:%')->count());
        app(Settings::class)->set(['billing.debt_reminder' => true], null);
        $this->artisan('tisu:billing-remind-debts')->assertSuccessful();
        $this->artisan('tisu:billing-remind-debts')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications_log')->where('user_id', $this->student->id)->where('kind', 'like', 'billing.debt:%')->count());
        $this->assertSame(1, DB::table('notifications_log')->where('user_id', $parent->id)->where('kind', 'like', 'billing.debt:%')->count());
        app(Settings::class)->set(['billing.debt_reminder_min' => 200000], null);
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->artisan('tisu:billing-remind-debts')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications_log')->where('user_id', $this->student->id)->where('kind', 'like', 'billing.debt:%')->count());
    }
}
