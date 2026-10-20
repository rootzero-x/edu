<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\GroupSchedule;
use App\Models\StudyGroup;
use App\Models\User;
use App\Support\SessionGenerator;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** #4 o'qituvchi huquqlari, #7 sertifikat bekor qilish, #21 chiqib ketganlar ko'rinadi, #20 bot o'qituvchi oqimi, §6 tezkor tuzatishlar. */
class IntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    private User $admin;

    private User $teacher;

    private User $student;

    private Course $course;

    private StudyGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 16:00:00'); // chorshanba
        config(['services.telegram.bot_token' => '1:T', 'services.telegram.webhook_secret' => self::SECRET]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create(['first_name' => 'Ustoz']);
        $this->student = User::factory()->create(['first_name' => 'Aziz']);
        foreach ([[$this->teacher, 101], [$this->student, 102]] as [$u, $tg]) {
            DB::table('telegram_links')->insert(['user_id' => $u->id, 'telegram_id' => $tg, 'chat_id' => $tg, 'linked_at' => now()]);
        }
        $this->course = Course::create(['title' => 'Ingliz tili', 'created_by' => $this->admin->id, 'published' => true]);
        $this->group = StudyGroup::create(['name' => 'ENG-1', 'course_id' => $this->course->id, 'branch_id' => Branch::create(['name' => 'B'])->id,
            'teacher_id' => $this->teacher->id, 'start_date' => '2026-09-01']);
        GroupSchedule::create(['group_id' => $this->group->id, 'weekday' => 3, 'start_time' => '14:00', 'end_time' => '15:30']);
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $this->student->id, 'joined_at' => '2026-09-01']);
        app(SessionGenerator::class)->generate($this->group->load('schedules'), '2026-09-01', '2026-10-28');
    }

    private function sid(string $date): int
    {
        return (int) DB::table('class_sessions')->where('group_id', $this->group->id)->where('starts_at', "$date 14:00:00")->value('id');
    }

    private function press(int $chat, string $data): void
    {
        $this->postJson('/v1/telegram/webhook', ['update_id' => 2, 'callback_query' => [
            'id' => 'cb'.$chat, 'data' => $data, 'from' => ['id' => $chat, 'first_name' => 'X'],
            'message' => ['message_id' => 50, 'chat' => ['id' => $chat, 'type' => 'private']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function text(int $chat, string $text): void
    {
        $this->postJson('/v1/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 1, 'text' => $text, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'X'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function requests(string $method, ?int $chat = null)
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn (HttpRequest $r) => str_contains($r->url(), "/$method") && ($chat === null || (int) ($r['chat_id'] ?? 0) === $chat))->values();
    }

    // ── #4 ───────────────────────────────────────────────────

    public function test_teacher_authors_own_courses_but_not_others(): void
    {
        $hidden = Course::create(['title' => 'Yashirin', 'created_by' => $this->admin->id, 'published' => false]);
        $paid = Course::create(['title' => 'Boshqa', 'created_by' => $this->admin->id, 'published' => true]);
        Sanctum::actingAs($this->teacher);
        $this->assertNotContains('course.manage', $this->getJson('/v1/auth/me')->json('user.permissions'));

        // Yangi kurs yaratadi va tahrirlaydi (course.author).
        $own = $this->postJson('/v1/courses', ['title' => 'Mening kursim'])->assertCreated()->json('course.id');
        $this->patchJson("/v1/courses/$own", ['title' => 'Yangi nom'])->assertOk();
        $this->postJson("/v1/courses/$own/modules", ['title' => 'M1'])->assertCreated();
        // Boshqaning kursini — yo'q (o'zi o'qitsa ham).
        $this->patchJson("/v1/courses/{$this->course->id}", ['title' => 'x'])->assertForbidden();
        $this->deleteJson("/v1/courses/{$paid->id}")->assertForbidden();

        $list = collect($this->getJson('/v1/courses')->assertOk()->json('data'));
        $this->assertFalse($list->contains('id', $hidden->id), 'boshqaning yashirin kursi ko\'rinmaydi');
        $this->assertTrue((bool) $list->firstWhere('id', $own)['can_edit']);
        $this->assertTrue((bool) $list->firstWhere('id', $this->course->id)['access']['granted'], "o'qitadigan kursi kontenti ochiq");
        $this->assertFalse((bool) $list->firstWhere('id', $paid->id)['access']['granted'], "o'qitmaydigan kursi kontenti yopiq");
        $this->getJson("/v1/courses/{$hidden->id}")->assertNotFound();
    }

    public function test_answer_key_only_for_author_and_admin_results_for_own_students(): void
    {
        Sanctum::actingAs($this->admin);
        $quizId = $this->postJson("/v1/courses/{$this->course->id}/quizzes", ['title' => 'T', 'published' => true])->json('quiz.id');
        $this->postJson("/v1/quizzes/$quizId/questions", ['type' => 'SINGLE', 'text' => '?', 'options' => [
            ['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]]])->assertCreated();

        Sanctum::actingAs($this->teacher); // o'qitadi, lekin muallif emas
        $this->getJson("/v1/quizzes/$quizId")->assertOk()->assertJsonMissingPath('quiz.questions');
        $this->getJson("/v1/quizzes/$quizId/results")->assertOk();
        $this->assertTrue(DB::table('audit_logs')->where('action', 'question.create')->exists());
    }

    // ── #7 ───────────────────────────────────────────────────

    public function test_certificate_revoke_keeps_record_and_reissue_gets_new_code(): void
    {
        app(Settings::class)->set(['certificate.require_final_quiz' => false, 'certificate.min_attendance_percent' => 0, 'certificate.min_marked_sessions' => 0], null);
        Sanctum::actingAs($this->admin);
        $code = $this->postJson("/v1/groups/{$this->group->id}/certificates")->assertOk()->json('issued.0.code');
        $this->getJson("/v1/public/certificates/$code")->assertOk()->assertJsonPath('certificate.status', 'VALID');

        $this->deleteJson("/v1/certificates/$code", ['reason' => "Xato bilan berilgan"])->assertOk();
        $this->deleteJson("/v1/certificates/$code")->assertStatus(409);
        $this->getJson("/v1/public/certificates/$code")->assertOk()->assertJsonPath('certificate.status', 'REVOKED')
            ->assertJsonPath('certificate.revoked_reason', 'Xato bilan berilgan');
        $this->assertSame($this->admin->id, (int) DB::table('certificates')->where('code', $code)->value('revoked_by'));

        // O'quvchi ro'yxatida yo'q, xodim ?revoked=1 bilan ko'radi.
        $this->getJson('/v1/certificates')->assertJsonCount(0, 'data');
        $this->getJson('/v1/certificates?revoked=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.revoked_reason', 'Xato bilan berilgan');

        // Qayta berish — yangi kod.
        $new = $this->postJson("/v1/groups/{$this->group->id}/certificates")->assertOk()->json('issued.0.code');
        $this->assertNotSame($code, $new);
        $this->getJson("/v1/public/certificates/$new")->assertJsonPath('certificate.status', 'VALID');
    }

    // ── #21 ──────────────────────────────────────────────────

    public function test_left_member_stays_visible_and_correctable_for_past_sessions(): void
    {
        $old = $this->sid('2026-10-07');
        DB::table('attendances')->insert(['session_id' => $old, 'student_id' => $this->student->id, 'status' => 'ABSENT', 'marked_by' => $this->teacher->id]);
        DB::table('group_members')->where('student_id', $this->student->id)->update(['left_at' => '2026-10-15 10:00:00']);

        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/sessions/$old/attendance")->assertOk()->assertJsonPath('rows.0.name', $this->student->first_name.' '.$this->student->last_name)
            ->assertJsonPath('rows.0.left', true);
        // Chiqib ketishdan oldingi, belgilanmagan dars ham varaqada.
        $this->getJson('/v1/sessions/'.$this->sid('2026-10-14').'/attendance')->assertJsonCount(1, 'rows');
        // Chiqib ketgandan keyingi darsda yo'q.
        $this->getJson('/v1/sessions/'.$this->sid('2026-10-21').'/attendance')->assertJsonCount(0, 'rows');
        // Tuzatish mumkin (sababli).
        $this->putJson("/v1/sessions/$old/attendance", ['items' => [['student_id' => $this->student->id, 'status' => 'EXCUSED']]])->assertOk();
        $this->putJson('/v1/sessions/'.$this->sid('2026-10-21').'/attendance', ['items' => [['student_id' => $this->student->id, 'status' => 'PRESENT']]])
            ->assertUnprocessable();
        // Guruh hisobotida ham (belgi bilan).
        $this->getJson("/v1/groups/{$this->group->id}/attendance")->assertJsonPath('data.0.left', true)->assertJsonPath('data.0.counts.EXCUSED', 1);

        // Sertifikat oldindan ko'rishda bor, lekin avtomatik berilmaydi.
        app(Settings::class)->set(['certificate.require_final_quiz' => false, 'certificate.min_attendance_percent' => 0], null);
        Sanctum::actingAs($this->admin);
        $this->getJson("/v1/groups/{$this->group->id}/certificates")->assertJsonPath('data.0.left', true);
        $this->postJson("/v1/groups/{$this->group->id}/certificates")->assertJsonCount(0, 'issued');
        $this->postJson("/v1/groups/{$this->group->id}/certificates", ['student_ids' => [$this->student->id]])->assertJsonCount(1, 'issued');
    }

    // ── #20 bot ──────────────────────────────────────────────

    public function test_bot_asks_reason_instead_of_failing_for_old_sessions(): void
    {
        app(Settings::class)->set(['attendance.edit_reason_required_after_days' => 3], null);
        $old = $this->sid('2026-10-14');
        $this->press(101, "a:m:$old:{$this->student->id}:E:0");
        $this->assertNull(DB::table('attendances')->where('session_id', $old)->value('status'), 'sababsiz saqlanmaydi');
        $prompt = $this->requests('sendMessage', 101)->last();
        $this->assertStringContainsString('7 kun oldin', (string) $prompt['text']);
        $this->assertTrue($prompt['reply_markup']['force_reply']);

        $this->text(101, "Ma'lumotnoma keltirdi");
        $this->assertSame('EXCUSED', DB::table('attendances')->where('session_id', $old)->value('status'));
        $audit = json_decode((string) DB::table('audit_logs')->where('action', 'attendance.mark')->value('data'), true);
        $this->assertSame("Ma'lumotnoma keltirdi", $audit['reason']);

        // /cancel sabab so'rovini bekor qiladi.
        $this->press(101, "a:m:$old:{$this->student->id}:A:0");
        $this->text(101, '/cancel');
        $this->text(101, 'bu endi sabab emas');
        $this->assertSame('EXCUSED', DB::table('attendances')->where('session_id', $old)->value('status'));
    }

    public function test_bot_sheet_is_paginated_beyond_page_size(): void
    {
        app(Settings::class)->set(['telegram.sheet_page_size' => 10], null);
        foreach (range(1, 24) as $i) {
            DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => User::factory()->create()->id, 'joined_at' => now()]);
        }
        $sid = $this->sid('2026-10-21');
        $this->press(101, "a:s:$sid");
        $kb = $this->requests('editMessageText', 101)->last()['reply_markup']['inline_keyboard'];
        $this->assertCount(10 + 3, $kb); // 10 o'quvchi + sahifalar + amallar + orqaga
        $this->assertSame('1/3', $kb[10][0]['text']);
        $this->assertSame("a:s:$sid:1", $kb[10][1]['callback_data']);

        $this->press(101, "a:s:$sid:2");
        $kb = $this->requests('editMessageText', 101)->last()['reply_markup']['inline_keyboard'];
        $this->assertCount(5 + 3, $kb);
        $this->assertSame('3/3', $kb[5][1]['text']);
    }

    public function test_unexpected_error_answers_callback_with_generic_text(): void
    {
        $this->mock(\App\Telegram\ApiBridge::class, fn ($m) => $m->shouldReceive('call')->andThrow(new \RuntimeException('SQLSTATE secret details')));
        $this->press(101, 'a:s:'.$this->sid('2026-10-21'));
        $answer = $this->requests('answerCallbackQuery')->last();
        $this->assertNotNull($answer);
        $this->assertStringContainsString('Xatolik yuz berdi', (string) $answer['text']);
        $this->assertStringNotContainsString('SQLSTATE', json_encode(collect(Http::recorded())->map(fn ($p) => $p[0]->data())->all()));
    }

    public function test_unlinked_callback_asks_for_phone(): void
    {
        $this->press(999, 'm:today');
        $this->assertStringContainsString('telefon raqamingizni', (string) $this->requests('answerCallbackQuery')->last()['text']);
    }

    public function test_unmarked_reminder_fires_for_partially_marked_sheet_once(): void
    {
        $second = User::factory()->create();
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $second->id, 'joined_at' => now()]);
        $sid = $this->sid('2026-10-21');
        DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $this->student->id, 'status' => 'PRESENT', 'marked_by' => $this->teacher->id]);
        $this->artisan('tisu:remind-unmarked')->assertSuccessful();
        $this->assertSame(1, $this->requests('sendMessage', 101)->count());
        $this->artisan('tisu:remind-unmarked')->assertSuccessful();
        $this->assertSame(1, $this->requests('sendMessage', 101)->count());
    }

    public function test_audit_for_password_change_logout_and_csv(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/v1/reports/attendance.csv')->assertOk();
        $this->assertTrue(DB::table('audit_logs')->where('action', 'report.attendance_csv')->where('actor_id', $this->admin->id)->exists());

        $token = $this->postJson('/v1/auth/login', ['phone' => $this->admin->phone, 'password' => 'secret-password'])->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/v1/auth/password', ['current_password' => 'secret-password', 'new_password' => 'new-secret-password-1'])->assertOk();
        $this->withToken($token)->postJson('/v1/auth/logout')->assertOk();
        $this->assertSame(['auth.login', 'auth.password_change', 'auth.logout'],
            DB::table('audit_logs')->where('entity', 'user')->where('actor_id', $this->admin->id)->orderBy('id')->pluck('action')->all());
    }
}
