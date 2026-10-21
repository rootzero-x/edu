<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\BillingEntry;
use App\Models\Course;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Billing;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A'zolik hayoti: tarix, muzlatish, ko'chirish, chiqish, bitirish va to'lov faqat ACTIVE kunlar uchun. */
class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private StudyGroup $group;

    private StudyGroup $group2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->student = User::factory()->create(['first_name' => 'Aziz']);
        $teacher = User::factory()->role(Role::Teacher)->create();
        $course = Course::create(['title' => 'Ingliz tili', 'created_by' => $this->admin->id]);
        $this->group = StudyGroup::create(['name' => 'ENG-1', 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'start_date' => '2026-09-01', 'active' => true]);
        $this->group2 = StudyGroup::create(['name' => 'ENG-2', 'course_id' => $course->id, 'teacher_id' => $teacher->id, 'start_date' => '2026-09-01', 'active' => true]);
        app(Settings::class)->set(['billing.monthly_fee' => 310000], null); // 31 kunlik oyda kuniga aniq 10 000
        Sanctum::actingAs($this->admin);
    }

    private function accrue(string $from, string $to): array
    {
        return app(Billing::class)->accrue(Carbon::parse($from), Carbon::parse($to));
    }

    private function charged(?int $groupId = null): int
    {
        return -(int) BillingEntry::where('student_id', $this->student->id)->where('type', 'CHARGE')->whereNull('voided_at')
            ->when($groupId, fn ($q) => $q->where('group_id', $groupId))->sum('amount');
    }

    private function url(string $suffix = ''): string
    {
        return "/v1/groups/{$this->group->id}/members/{$this->student->id}".$suffix;
    }

    public function test_freeze_in_the_middle_of_a_31_day_month_charges_only_active_days(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-10', 'to' => '2026-10-19', 'reason' => 'Kasallik'])
            ->assertOk()->assertJson(['days' => 10, 'refunded' => 0]);

        Carbon::setTestNow('2026-11-01 01:00:00');
        $r = $this->accrue('2026-10-01', '2026-10-31');

        // 31 kun − 10 muzlatilgan kun = 21 kun × 10 000.
        $this->assertSame(210000, $r['amount']);
        $this->assertSame(210000, $this->charged());
        $days = BillingEntry::where('student_id', $this->student->id)->pluck('entry_date')->map(fn ($d) => $d->day)->all();
        $this->assertNotContains(10, $days);
        $this->assertNotContains(19, $days);
        $this->assertContains(9, $days);
        $this->assertContains(20, $days);
        $this->assertContains(31, $days);
        // Qayta ishga tushirish dublikat yaratmaydi.
        $this->assertSame(0, $this->accrue('2026-10-01', '2026-10-31')['created']);
    }

    public function test_backdated_freeze_refunds_already_charged_days_once(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->accrue('2026-10-01', '2026-10-15'); // 15 kun hisoblangan
        $this->assertSame(150000, $this->charged());

        $this->postJson($this->url('/freeze'), ['from' => '2026-10-10', 'to' => '2026-10-19', 'reason' => 'Kasallik'])
            ->assertOk()->assertJson(['refunded' => 60000]); // 10..15 — 6 kun qaytarildi
        $adj = BillingEntry::where('student_id', $this->student->id)->where('type', 'ADJUSTMENT')->sole();
        $this->assertSame(60000, $adj->amount);
        $this->assertSame($this->group->id, $adj->group_id);

        Carbon::setTestNow('2026-11-01 01:00:00');
        $this->accrue('2026-10-16', '2026-10-31');
        // Jami: 31 − 10 = 21 kun.
        $this->assertSame(-210000, app(Billing::class)->balance($this->student->id));
    }

    public function test_unfreeze_early_resumes_charging_and_future_freeze_can_be_cancelled(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-05', 'to' => '2026-10-25', 'reason' => 'Safar'])->assertOk();

        Carbon::setTestNow('2026-10-08 10:00:00');
        $members = $this->getJson("/v1/groups/{$this->group->id}/members")->assertOk()->json('current');
        $this->assertSame('FROZEN', $members[0]['status']);
        $this->assertSame('2026-10-25', $members[0]['until']);

        $this->postJson($this->url('/unfreeze'))->assertOk(); // 8-oktabrdan yana o'qiydi
        $this->assertSame('ACTIVE', $this->getJson("/v1/groups/{$this->group->id}/members")->json('current.0.status'));

        // Kelajakdagi muzlatishni bekor qilish — tarixda iz qolmaydi.
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-20', 'to' => '2026-10-22', 'reason' => 'Safar'])->assertOk();
        $this->assertSame('2026-10-20', $this->getJson("/v1/groups/{$this->group->id}/members")->json('current.0.frozen_from'));
        $this->postJson($this->url('/unfreeze'))->assertOk();

        Carbon::setTestNow('2026-11-01 01:00:00');
        $this->accrue('2026-10-01', '2026-10-31');
        // Muzlatilgan: 5, 6, 7 oktabr (3 kun) → 28 kun.
        $this->assertSame(280000, $this->charged());
        $statuses = DB::table('enrollments')->orderBy('starts_on')->get(['status', 'starts_on', 'ends_on'])
            ->map(fn ($r) => $r->status.':'.substr($r->starts_on, 0, 10).':'.($r->ends_on ? substr($r->ends_on, 0, 10) : '-'))->all();
        $this->assertSame(['ACTIVE:2026-10-01:2026-10-04', 'FROZEN:2026-10-05:2026-10-07', 'ACTIVE:2026-10-08:-'], $statuses);
    }

    public function test_overlapping_freeze_and_limits_are_rejected(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-10', 'to' => '2026-10-12', 'reason' => 'Safar'])->assertOk();
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-11', 'to' => '2026-10-15', 'reason' => 'Safar'])->assertStatus(422)->assertJsonValidationErrors('from');
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-05', 'to' => '2026-10-11', 'reason' => 'Safar'])->assertStatus(422);
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-20', 'to' => '2026-10-21'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($this->url('/freeze'), ['from' => '2026-11-01', 'to' => '2027-03-01', 'reason' => 'Safar'])->assertStatus(422)->assertJsonValidationErrors('to');
        $this->postJson($this->url('/freeze'), ['from' => '2026-08-01', 'to' => '2026-08-05', 'reason' => 'Safar'])->assertStatus(422);
    }

    public function test_leave_with_reason_keeps_history_and_rejoin_opens_a_new_period(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->postJson($this->url('/leave'), [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($this->url('/leave'), ['reason' => 'Narx qimmat'])->assertOk();
        $this->postJson($this->url('/leave'), ['reason' => 'Narx qimmat'])->assertStatus(422);

        $former = $this->getJson("/v1/groups/{$this->group->id}/members")->assertOk()->json('former');
        $this->assertSame('LEFT', $former[0]['status']);
        $this->assertSame('Narx qimmat', $former[0]['reason']);
        $this->assertSame('2026-10-10', $former[0]['left_on']);

        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id])->assertCreated()->assertJson(['result' => 'rejoined']);
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id])->assertOk()->assertJson(['result' => 'already']);

        $history = $this->getJson($this->url('/history'))->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertSame('2026-10-20', $history[0]['starts_on']);
        $this->assertSame('LEFT', $history[1]['outcome']);

        Carbon::setTestNow('2026-11-01 01:00:00');
        $this->accrue('2026-10-01', '2026-10-31');
        // 1..10 (10 kun, chiqqan kun ham) + 20..31 (12 kun).
        $this->assertSame(220000, $this->charged());
    }

    public function test_rejoining_on_the_day_of_leaving_undoes_the_leave(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->postJson($this->url('/leave'), ['reason' => 'Narx qimmat'])->assertOk();

        // Xato chiqarilgan — o'sha kuni qaytarildi: davr davom etadi, tarixda "chiqib ketgan" qolmaydi.
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id])->assertCreated()->assertJson(['result' => 'restored']);
        $this->assertNull(DB::table('group_members')->value('left_at'));
        $history = $this->getJson($this->url('/history'))->json('data');
        $this->assertCount(1, $history);
        $this->assertSame(['2026-10-01', null, null, null], [$history[0]['starts_on'], $history[0]['ends_on'], $history[0]['outcome'], $history[0]['reason']]);
        $this->assertSame([], $this->getJson("/v1/groups/{$this->group->id}/members")->json('former'));

        Carbon::setTestNow('2026-11-01 01:00:00');
        $this->accrue('2026-10-01', '2026-10-31');
        $this->assertSame(310000, $this->charged()); // butun oy, uzilishsiz
    }

    public function test_backdated_or_older_leave_is_not_undone_by_rejoin(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->postJson($this->url('/leave'), ['reason' => 'Narx qimmat', 'date' => '2026-10-08'])->assertOk();
        // Orqa sana bilan chiqarilgan — bugun qayta qo'shish yangi davr ochadi, eski tarix saqlanadi.
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id])->assertCreated()->assertJson(['result' => 'rejoined']);
        $this->assertCount(2, $this->getJson($this->url('/history'))->json('data'));

        // Ertasi kuni — chiqarishni bekor qilish yo'q, sanasi ertaroq bo'lsa rad etiladi.
        $other = User::factory()->create();
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $other->id])->assertCreated();
        $this->postJson("/v1/groups/{$this->group->id}/members/{$other->id}/leave", ['reason' => 'Boshqa'])->assertOk();
        Carbon::setTestNow('2026-10-11 12:00:00');
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $other->id, 'start_date' => '2026-10-10'])->assertStatus(422);
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $other->id])->assertCreated()->assertJson(['result' => 'rejoined']);
    }

    public function test_legacy_delete_still_works_and_same_day_mistake_is_not_charged(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id])->assertCreated();
        $this->deleteJson($this->url())->assertOk();
        $this->assertNotNull(DB::table('group_members')->value('left_at'));
        $this->accrue('2026-10-01', '2026-10-01');
        $this->assertSame(0, $this->charged());
    }

    public function test_transfer_closes_old_period_and_opens_new_one_without_double_charge(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id, 'start_date' => '2026-10-01'])->assertCreated();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->accrue('2026-10-01', '2026-10-15');
        $this->postJson($this->url('/transfer'), ['to_group_id' => $this->group->id])->assertStatus(422);
        $this->postJson($this->url('/transfer'), ['to_group_id' => $this->group2->id, 'date' => '2026-10-15', 'reason' => "Vaqt to'g'ri kelmadi"])->assertOk();

        $this->assertNull(DB::table('group_members')->where(['group_id' => $this->group2->id, 'student_id' => $this->student->id])->value('left_at'));
        $former = $this->getJson("/v1/groups/{$this->group->id}/members")->json('former.0');
        $this->assertSame('TRANSFERRED', $former['status']);
        $this->assertSame('ENG-2', $former['transfer_group']['name']);
        // 15-oktabr eski guruhda hisoblangan edi — qaytarildi.
        $this->assertSame(10000, (int) BillingEntry::where('type', 'ADJUSTMENT')->sum('amount'));

        Carbon::setTestNow('2026-11-01 01:00:00');
        $this->accrue('2026-10-15', '2026-10-31');
        $this->assertSame(150000, $this->charged($this->group->id)); // 1..15 hisoblangan, 15-kun tuzatish bilan qaytarilgan
        $this->assertSame(170000, $this->charged($this->group2->id)); // 15..31
        $this->assertSame(-310000, app(Billing::class)->balance($this->student->id)); // jami aynan bir oy
    }

    public function test_graduate_all_and_frozen_student_graduates_too(): void
    {
        $other = User::factory()->create();
        $this->postJson("/v1/groups/{$this->group->id}/members/bulk", ['student_ids' => [$this->student->id, $other->id, $this->admin->id], 'start_date' => '2026-10-01'])
            ->assertOk()->assertJson(['added' => 2, 'already' => 0])->assertJsonCount(1, 'errors');
        $this->postJson("/v1/groups/{$this->group->id}/members/bulk", ['student_ids' => [$this->student->id]])->assertJson(['added' => 0, 'already' => 1]);
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-01', 'to' => '2026-10-05', 'reason' => 'Safar'])->assertOk();
        Carbon::setTestNow('2026-10-03 12:00:00');
        $this->postJson("/v1/groups/{$this->group->id}/graduate")->assertOk()->assertJson(['graduated' => 2]);
        $former = collect($this->getJson("/v1/groups/{$this->group->id}/members")->json('former'));
        $this->assertSame(['GRADUATED'], $former->pluck('status')->unique()->values()->all());
        // Muzlatish sababi o'chib ketmaydi.
        $this->assertSame('Safar', DB::table('enrollments')->where('student_id', $this->student->id)->where('outcome', 'GRADUATED')->value('reason'));
    }

    public function test_members_endpoint_is_staff_only(): void
    {
        $this->postJson("/v1/groups/{$this->group->id}/members", ['student_id' => $this->student->id])->assertCreated();
        Sanctum::actingAs($this->student);
        $this->getJson("/v1/groups/{$this->group->id}/members")->assertForbidden();
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-02', 'to' => '2026-10-03', 'reason' => 'x'])->assertForbidden();
    }

    public function test_legacy_membership_without_history_gets_backfilled_on_first_action(): void
    {
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $this->student->id, 'joined_at' => '2026-09-20 10:00:00']);
        $this->postJson($this->url('/freeze'), ['from' => '2026-10-05', 'to' => '2026-10-06', 'reason' => 'Safar'])->assertOk();
        $this->assertSame(3, DB::table('enrollments')->count());
        $this->assertSame('2026-09-20', substr((string) DB::table('enrollments')->orderBy('starts_on')->value('starts_on'), 0, 10));
    }
}
