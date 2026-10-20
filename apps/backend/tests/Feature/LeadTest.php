<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Lead;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Arizalar: sayt formasi (spamdan himoya), CRM jarayoni, o'quvchiga aylantirish, voronka, bot «Ariza». */
class LeadTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    private const ADMIN_CHAT = 6817322973;

    private User $admin;

    private Course $course;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        config(['services.telegram.bot_token' => '1:T', 'services.telegram.webhook_secret' => self::SECRET]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->admin = User::factory()->role(Role::Admin)->create(['first_name' => 'Admin']);
        DB::table('telegram_links')->insert(['user_id' => $this->admin->id, 'telegram_id' => self::ADMIN_CHAT, 'chat_id' => self::ADMIN_CHAT, 'linked_at' => now()]);
        app(Settings::class)->set(['telegram.admin_chat_ids' => [self::ADMIN_CHAT]], null);
        $this->course = Course::create(['title' => 'Ingliz tili', 'created_by' => $this->admin->id, 'published' => true]);
        $teacher = User::factory()->role(Role::Teacher)->create();
        $this->group = StudyGroup::create(['name' => 'ENG-1', 'course_id' => $this->course->id, 'teacher_id' => $teacher->id, 'start_date' => '2026-09-01', 'active' => true]);
    }

    private function form(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/v1/public/leads', $over + [
            'name' => 'Ali Valiyev', 'phone' => '90 123 45 67', 'course_id' => $this->course->id,
            'comment' => 'Kechqurun', 'consent' => true, 'fill_ms' => 8000, 'website' => '',
        ]);
    }

    private function adminMessages(): array
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), 'sendMessage') && ($r['chat_id'] ?? null) == self::ADMIN_CHAT)
            ->map(fn (HttpRequest $r) => $r['text'])->values()->all();
    }

    public function test_public_form_creates_lead_and_alerts_admins(): void
    {
        $this->form()->assertCreated()->assertJsonPath('message', app(Settings::class)->get('leads.success_text'));
        $lead = Lead::sole();
        $this->assertSame('+998901234567', $lead->phone);
        $this->assertSame('site', $lead->source);
        $this->assertSame('NEW', $lead->status);
        $this->assertSame($this->course->id, $lead->course_id);
        $this->assertNotNull($lead->consent_at);
        $this->assertSame('2026-10-21', $lead->toApi()['next_contact_on']);
        $msgs = $this->adminMessages();
        $this->assertCount(1, $msgs);
        $this->assertStringContainsString('Ali Valiyev', $msgs[0]);
        $this->assertStringContainsString('Ingliz tili', $msgs[0]);
    }

    public function test_spam_guards_honeypot_speed_consent_and_phone(): void
    {
        app(Settings::class)->set(['leads.public_per_hour' => 100, 'leads.public_per_phone_per_day' => 100], null);
        $this->form(['website' => 'http://spam'])->assertCreated();
        $this->form(['fill_ms' => 500])->assertCreated();
        $this->postJson('/v1/public/leads', ['name' => 'Bot', 'phone' => '901234567', 'consent' => true])->assertCreated(); // fill_ms yo'q
        $this->assertSame(0, Lead::count());
        $this->form(['phone' => '12345'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->form(['consent' => false, 'phone' => '+998 91 000 00 01'])->assertStatus(422)->assertJsonValidationErrors('consent');
        $this->assertSame(0, Lead::count());
        // Nashr qilinmagan kurs id'si e'tiborsiz qoldiriladi.
        $hidden = Course::create(['title' => 'Yashirin', 'created_by' => $this->admin->id, 'published' => false]);
        $this->form(['course_id' => $hidden->id])->assertCreated();
        $this->assertNull(Lead::sole()->course_id);
        app(Settings::class)->set(['leads.form_enabled' => false], null);
        $this->form()->assertNotFound();
    }

    public function test_duplicate_submission_merges_into_open_lead_and_rate_limit_applies(): void
    {
        app(Settings::class)->set(['leads.public_per_hour' => 3], null);
        $this->form()->assertCreated();
        $this->form(['phone' => '+998901234567', 'comment' => 'Yana yozdim'])->assertCreated();
        $this->assertSame(1, Lead::count());
        $this->assertSame(1, Lead::sole()->activities()->where('data->event', 'duplicate')->count());
        $this->assertCount(1, $this->adminMessages());
        $this->form(['phone' => '911112233'])->assertCreated();
        $this->form(['phone' => '911112234'])->assertStatus(429);
    }

    public function test_crm_flow_call_trial_lost_and_permissions(): void
    {
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/v1/leads', ['name' => 'Vali', 'phone' => '93 111 22 33', 'source' => 'phone'])->assertCreated()->json('lead.id');
        $this->postJson('/v1/leads', ['name' => 'Vali 2', 'phone' => '+998931112233', 'source' => 'phone'])
            ->assertStatus(422)->assertJsonPath('existing_lead_id', $id);
        $this->postJson('/v1/leads', ['name' => 'X', 'phone' => '931112244', 'source' => 'tv'])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->getJson('/v1/leads?due=today')->assertOk()->assertJsonPath('due_today', 1)->assertJsonCount(1, 'data');
        $this->getJson('/v1/leads?q=93 111')->assertJsonCount(1, 'data');
        $this->getJson('/v1/leads?q=Vali')->assertJsonCount(1, 'data');

        $this->postJson("/v1/leads/$id/activities", ['type' => 'CALL', 'text' => "Qo'ng'iroq qilindi, sinovga keladi", 'next_contact_on' => '2026-10-23'])
            ->assertCreated()->assertJsonPath('lead.status', 'CONTACTED')->assertJsonPath('lead.next_contact_on', '2026-10-23');

        $session = ClassSession::create(['group_id' => $this->group->id, 'starts_at' => '2026-10-22 14:00:00', 'ends_at' => '2026-10-22 15:30:00']);
        $this->getJson('/v1/leads/trial-sessions?course_id='.$this->course->id)->assertOk()->assertJsonPath('data.0.id', $session->id);
        $this->postJson("/v1/leads/$id/status", ['status' => 'TRIAL', 'trial_session_id' => $session->id])->assertOk()
            ->assertJsonPath('lead.trial.session_id', $session->id)->assertJsonPath('lead.next_contact_on', '2026-10-23')
            ->assertJsonPath('lead.course.id', $this->course->id);
        $this->postJson("/v1/leads/$id/status", ['status' => 'LOST'])->assertStatus(422)->assertJsonValidationErrors('lost_reason');
        $this->postJson("/v1/leads/$id/status", ['status' => 'ENROLLED'])->assertStatus(422);
        $this->postJson("/v1/leads/$id/status", ['status' => 'LOST', 'lost_reason' => 'Narx qimmat'])->assertOk()->assertJsonPath('lead.next_contact_on', null);

        $show = $this->getJson("/v1/leads/$id")->assertOk();
        $this->assertSame(['STATUS', 'STATUS', 'STATUS', 'CALL', 'SYSTEM'], array_column($show->json('activities'), 'type'));
        // Yopilgan ariza bo'lsa, xuddi shu raqamga yangi ariza ochish mumkin.
        $this->postJson('/v1/leads', ['name' => 'Vali', 'phone' => '931112233', 'source' => 'walk_in'])->assertCreated();

        $teacher = User::factory()->role(Role::Teacher)->create();
        Sanctum::actingAs($teacher);
        $this->getJson('/v1/leads')->assertForbidden();
    }

    public function test_convert_creates_student_and_membership_in_one_step(): void
    {
        $this->form(['name' => 'Malika Karimova', 'phone' => '90 555 44 33'])->assertCreated();
        $lead = Lead::sole();
        Sanctum::actingAs($this->admin);
        $this->postJson("/v1/leads/{$lead->id}/convert", ['first_name' => 'Malika', 'phone' => '12'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $r = $this->postJson("/v1/leads/{$lead->id}/convert", [
            'first_name' => 'Malika', 'last_name' => 'Karimova', 'phone' => '90 555 44 33', 'group_id' => $this->group->id,
        ])->assertOk()->assertJson(['created' => true, 'joined' => 'added'])->assertJsonPath('lead.status', 'ENROLLED');

        $student = User::where('phone', '+998905554433')->sole();
        $this->assertSame(Role::Student, $student->role);
        $this->assertTrue(DB::table('group_members')->where(['group_id' => $this->group->id, 'student_id' => $student->id])->whereNull('left_at')->exists());
        $this->assertSame(1, DB::table('enrollments')->where('student_id', $student->id)->count());
        $this->assertSame($student->id, $r->json('student.id'));
        $this->assertSame($student->id, $r->json('lead.student_id'));
        $this->postJson("/v1/leads/{$lead->id}/convert", ['first_name' => 'Malika', 'phone' => '905554433'])->assertStatus(422);

        // Mavjud o'quvchi qayta ishlatiladi; boshqa roldagi raqam rad etiladi.
        $id2 = $this->postJson('/v1/leads', ['name' => 'Malika', 'phone' => '905554433', 'source' => 'referral'])->assertCreated()->json('lead.id');
        $this->postJson("/v1/leads/$id2/convert", ['first_name' => 'Malika', 'phone' => '+998905554433'])->assertOk()->assertJson(['created' => false]);
        User::factory()->role(Role::Teacher)->create(['phone' => '+998901110000']);
        $id3 = $this->postJson('/v1/leads', ['name' => 'T', 'phone' => '901110000', 'source' => 'other'])->json('lead.id');
        $this->getJson("/v1/leads/$id3")->assertJsonPath('existing_user.role', 'TEACHER');
        $this->postJson("/v1/leads/$id3/convert", ['first_name' => 'T', 'phone' => '901110000'])->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame(1, User::where('phone', '+998901110000')->count());
    }

    public function test_funnel_stats_by_source_and_course(): void
    {
        Sanctum::actingAs($this->admin);
        foreach ([['site', 'ENROLLED'], ['site', 'LOST'], ['site', 'NEW'], ['bot', 'CONTACTED']] as $i => [$source, $status]) {
            Lead::create(['name' => "L$i", 'phone' => '+99890000000'.$i, 'source' => $source, 'status' => $status, 'course_id' => $this->course->id,
                'lost_reason' => $status === 'LOST' ? 'Narx qimmat' : null]);
        }
        $r = $this->getJson('/v1/leads/stats')->assertOk();
        $this->assertSame(4, $r->json('total.total'));
        $this->assertEquals(25.0, $r->json('total.conversion'));
        $site = collect($r->json('by_source'))->firstWhere('source', 'site');
        $this->assertSame(['total' => 3, 'enrolled' => 1, 'lost' => 1], array_intersect_key($site, ['total' => 1, 'enrolled' => 1, 'lost' => 1]));
        $this->assertSame('Ingliz tili', $r->json('by_course.0.course_title'));
        $this->assertSame('Narx qimmat', $r->json('lost_reasons.0.reason'));
        $this->getJson('/v1/leads?status=NEW,CONTACTED')->assertJsonCount(2, 'data')->assertJsonPath('counts.ENROLLED', 1);
    }

    private function markup(): array
    {
        $m = collect(Http::recorded())->last()[0]['reply_markup'];

        return is_string($m) ? json_decode($m, true) : $m;
    }

    private function webhook(array $update): void
    {
        $this->postJson('/v1/telegram/webhook', $update + ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_bot_ariza_flow_creates_lead_without_creating_an_account(): void
    {
        $tg = 555001;
        $msg = fn (array $m) => ['message' => $m + ['message_id' => 1, 'chat' => ['id' => $tg, 'type' => 'private'], 'from' => ['id' => $tg, 'first_name' => 'Sardor']]];
        // Bog'lanmagan foydalanuvchiga telefon so'raladi — klaviaturada «Ariza qoldirish» ham bor.
        $this->webhook($msg(['text' => '/start']));
        $kb = $this->markup();
        $this->assertSame('📝 Ariza qoldirish', $kb['keyboard'][1][0]['text']);

        $this->webhook($msg(['text' => '📝 Ariza qoldirish']));
        $kb = $this->markup();
        $this->assertSame('ld:c:'.$this->course->id, $kb['inline_keyboard'][0][0]['callback_data']);

        $this->webhook(['callback_query' => ['id' => 'cb1', 'data' => 'ld:c:'.$this->course->id, 'from' => ['id' => $tg, 'first_name' => 'Sardor'],
            'message' => ['message_id' => 9, 'chat' => ['id' => $tg, 'type' => 'private']]]]);
        $this->webhook($msg(['contact' => ['phone_number' => '998935556677', 'first_name' => 'Sardor', 'user_id' => $tg]]));

        $lead = Lead::sole();
        $this->assertSame(['bot', '+998935556677', $this->course->id, $tg], [$lead->source, $lead->phone, $lead->course_id, $lead->telegram_id]);
        $this->assertNull(User::where('phone', '+998935556677')->first());
        $this->assertFalse(DB::table('telegram_links')->where('telegram_id', $tg)->exists());
        $this->assertCount(1, $this->adminMessages());

        // Keyingi kontakt — oddiy ro'yxatdan o'tish (ariza holati tugagan).
        $this->webhook($msg(['contact' => ['phone_number' => '998935556677', 'first_name' => 'Sardor', 'user_id' => $tg]]));
        $this->assertNotNull(User::where('phone', '+998935556677')->first());
    }

    public function test_linked_user_applies_without_sharing_phone_again(): void
    {
        $student = User::factory()->create(['phone' => '+998977001122', 'first_name' => 'Ota']);
        DB::table('telegram_links')->insert(['user_id' => $student->id, 'telegram_id' => 7001, 'chat_id' => 7001, 'linked_at' => now()]);
        $this->webhook(['message' => ['message_id' => 1, 'text' => '/ariza', 'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ota']]]);
        $this->webhook(['callback_query' => ['id' => 'cb2', 'data' => 'ld:c:0', 'from' => ['id' => 7001, 'first_name' => 'Ota'],
            'message' => ['message_id' => 9, 'chat' => ['id' => 7001, 'type' => 'private']]]]);
        $lead = Lead::sole();
        $this->assertSame(['+998977001122', null], [$lead->phone, $lead->course_id]);

        app(Settings::class)->set(['leads.bot_enabled' => false, 'site.contact_phone' => '+998 71 200 00 00'], null);
        $this->webhook(['message' => ['message_id' => 2, 'text' => '/ariza', 'chat' => ['id' => 7001, 'type' => 'private'], 'from' => ['id' => 7001, 'first_name' => 'Ota']]]);
        $this->assertStringContainsString('+998 71 200 00 00', (string) collect(Http::recorded())->last()[0]['text']);
    }
}
