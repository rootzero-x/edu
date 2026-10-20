<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Bot menyusi (rollar bo'yicha), inline davomat, admin cheklovi va xabar tarqatish. */
class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    private const ADMIN_CHAT = 6817322973;

    private User $teacher;

    private User $student;

    private User $parent;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 16:00:00'); // chorshanba
        config(['services.telegram.bot_token' => '1:T', 'services.telegram.webhook_secret' => self::SECRET]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create(['first_name' => 'Ustoz']);
        $this->student = User::factory()->create(['first_name' => 'Aziz', 'last_name' => 'K']);
        $this->parent = User::factory()->role(Role::Parent)->create();
        // Admin chati faol, user.manage huquqli akkauntga bog'langan (bot adminligi sharti).
        foreach ([[$admin, self::ADMIN_CHAT], [$this->teacher, 101], [$this->student, 102], [$this->parent, 103]] as [$u, $tg]) {
            DB::table('telegram_links')->insert(['user_id' => $u->id, 'telegram_id' => $tg, 'chat_id' => $tg, 'linked_at' => now()]);
        }
        $course = Course::create(['title' => 'Ingliz tili', 'created_by' => $admin->id, 'published' => true]);
        Sanctum::actingAs($admin);
        $this->groupId = $this->postJson('/v1/groups', [
            'name' => 'ENG-1', 'course_id' => $course->id, 'branch_id' => Branch::create(['name' => 'Markaz'])->id,
            'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-19',
            'schedules' => [['weekday' => 3, 'start_time' => '14:00', 'end_time' => '15:30']],
        ])->assertCreated()->json('group.id');
        $this->postJson("/v1/groups/{$this->groupId}/members", ['student_id' => $this->student->id])->assertCreated();
        $this->postJson("/v1/users/{$this->parent->id}/children", ['child_id' => $this->student->id])->assertCreated();
        $this->postJson("/v1/groups/{$this->groupId}/sessions/generate", ['from' => '2026-10-19', 'to' => '2026-10-30'])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    private function text(int $chatId, string $text, array $from = []): void
    {
        $this->postJson('/v1/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 1, 'text' => $text, 'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => $from + ['id' => $chatId, 'first_name' => 'X'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    private function press(int $chatId, string $data): void
    {
        $this->postJson('/v1/telegram/webhook', ['update_id' => 2, 'callback_query' => [
            'id' => 'cb'.$chatId, 'data' => $data, 'from' => ['id' => $chatId, 'first_name' => 'X'],
            'message' => ['message_id' => 50, 'chat' => ['id' => $chatId, 'type' => 'private']],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /** Oxirgi yuborilgan (sendMessage/editMessageText) so'rov. */
    private function last(?int $chatId = null): HttpRequest
    {
        $hits = collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn ($r) => preg_match('/(sendMessage|editMessageText)$/', $r->url()) && ($chatId === null || (int) $r['chat_id'] === $chatId));
        $this->assertNotEmpty($hits, 'Bot hech narsa yubormadi.');

        return $hits->last();
    }

    private function callbackAnswer(): HttpRequest
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery'))->last();
    }

    private function sessionId(string $startsAt): int
    {
        return (int) DB::table('class_sessions')->where('group_id', $this->groupId)->where('starts_at', $startsAt)->value('id');
    }

    // ── Rollar bo'yicha menyu ────────────────────────────────

    public function test_student_menu_keyboard_and_sections(): void
    {
        $this->text(102, '/start');
        $keyboard = collect($this->last(102)['reply_markup']['keyboard'])->flatten(1)->pluck('text');
        foreach (['📚 Kurslarim', '🗓 Jadval', '✅ Davomatim', '📝 Testlarim', '🎓 Sertifikatlarim', '👤 Profil'] as $label) {
            $this->assertContains($label, $keyboard);
        }
        $this->assertNotContains('📋 Bugungi darslar', $keyboard);
        $this->assertNotContains('🛠 Admin panel', $keyboard);

        $this->text(102, '📚 Kurslarim');
        $this->assertStringContainsString('Ingliz tili', $this->last(102)['text']);
        $this->assertStringEndsWith('/tg/courses/'.DB::table('courses')->value('id'),
            $this->last(102)['reply_markup']['inline_keyboard'][0][0]['web_app']['url']);

        $this->text(102, '🗓 Jadval');
        $this->assertStringContainsString('14:00–15:30  ENG-1', $this->last(102)['text']);

        $this->text(102, '/certificates');
        $this->assertStringContainsString("sertifikat yo'q", $this->last(102)['text']);

        $this->text(102, '👤 Profil');
        $this->assertStringContainsString('Aziz K', $this->last(102)['text']);
    }

    public function test_student_attendance_and_quizzes_use_api_logic(): void
    {
        $sid = $this->sessionId('2026-10-21 14:00:00');
        DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $this->student->id, 'status' => 'LATE', 'marked_by' => $this->teacher->id]);
        $course = Course::first();
        $course->quizzes()->create(['title' => 'Yakuniy', 'published' => true, 'pass_percent' => 60, 'max_attempts' => 3]);

        $this->text(102, '✅ Davomatim');
        $this->assertStringContainsString('«ENG-1» — 100%', $this->last(102)['text']);
        $this->assertStringContainsString('⏰ 1', $this->last(102)['text']);

        $this->text(102, '📝 Testlarim');
        $this->assertStringContainsString('Yakuniy (Ingliz tili) — 🆕', $this->last(102)['text']);
    }

    public function test_student_sees_quizzes_of_own_unpublished_course(): void
    {
        // courses.published faqat katalog uchun: o'z guruhining kursi yopiq bo'lsa ham testlari ko'rinadi.
        $course = Course::first();
        $course->update(['published' => false]);
        $course->quizzes()->create(['title' => 'Yakuniy', 'published' => true, 'pass_percent' => 60, 'max_attempts' => 3]);

        $this->text(102, '📝 Testlarim');
        $this->assertStringContainsString('Yakuniy (Ingliz tili)', $this->last(102)['text']);
        $this->text(102, '📚 Kurslarim');
        $this->assertStringContainsString('Ingliz tili', $this->last(102)['text']);
    }

    public function test_menu_texts_and_layout_come_from_settings(): void
    {
        app(Settings::class)->set([
            'telegram.text.btn_schedule' => 'Dars vaqtlari',
            'telegram.menu' => ['STUDENT' => [['schedule']]],
        ], null);
        $this->text(102, '/menu');
        $this->assertSame([[['text' => 'Dars vaqtlari']]], $this->last(102)['reply_markup']['keyboard']);
        $this->text(102, 'Dars vaqtlari');
        $this->assertStringContainsString('ENG-1', $this->last(102)['text']);
    }

    public function test_unlinked_user_is_asked_for_phone(): void
    {
        $this->text(999, '🗓 Jadval');
        $this->assertTrue($this->last(999)['reply_markup']['keyboard'][0][0]['request_contact']);
    }

    public function test_parent_sees_children_attendance(): void
    {
        $sid = $this->sessionId('2026-10-21 14:00:00');
        DB::table('attendances')->insert(['session_id' => $sid, 'student_id' => $this->student->id, 'status' => 'ABSENT', 'marked_by' => $this->teacher->id]);
        $this->text(103, '👨‍👩‍👧 Farzandlarim');
        $text = $this->last(103)['text'];
        $this->assertStringContainsString('Aziz K', $text);
        $this->assertStringContainsString('«ENG-1» — 0%', $text);
        $this->assertStringContainsString('21.10', $text);
    }

    // ── O'qituvchi: inline davomat ───────────────────────────

    public function test_teacher_marks_attendance_with_inline_buttons(): void
    {
        $sid = $this->sessionId('2026-10-21 14:00:00');
        $this->text(101, '📋 Bugungi darslar');
        $button = $this->last(101)['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame("a:s:$sid", $button['callback_data']);
        $this->assertStringContainsString('ENG-1 (0/1)', $button['text']);

        $this->press(101, "a:s:$sid");
        $this->assertSame("a:p:$sid:{$this->student->id}", $this->last(101)['reply_markup']['inline_keyboard'][0][0]['callback_data']);

        $this->press(101, "a:p:$sid:{$this->student->id}");
        $this->assertStringContainsString('Aziz K', $this->last(101)['text']);

        $this->press(101, "a:m:$sid:{$this->student->id}:A");
        $this->assertSame('ABSENT', DB::table('attendances')->where('session_id', $sid)->value('status'));
        // Ota-onaga xabar AttendanceController::mark orqali ketdi.
        $this->assertSame(1, DB::table('notifications_log')->where(['user_id' => $this->parent->id, 'kind' => 'attendance.ABSENT'])->count());
        $this->assertStringContainsString('Ota-onaga 1 ta', $this->callbackAnswer()['text']);
        $this->assertStringContainsString('❌ Aziz K', $this->last(101)['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertSame('attendance.mark', DB::table('audit_logs')->where('actor_id', $this->teacher->id)->value('action'));
    }

    public function test_all_present_button_marks_only_unmarked(): void
    {
        $sid = $this->sessionId('2026-10-21 14:00:00');
        $this->press(101, "a:all:$sid");
        $this->assertSame('PRESENT', DB::table('attendances')->where('session_id', $sid)->value('status'));
        $this->press(101, "a:all:$sid");
        $this->assertSame('Hamma belgilangan.', $this->callbackAnswer()['text']);
    }

    public function test_attendance_buttons_respect_api_permissions(): void
    {
        $sid = $this->sessionId('2026-10-21 14:00:00');
        // O'quvchida attendance.mark yo'q.
        $this->press(102, "a:m:$sid:{$this->student->id}:P");
        $this->assertTrue($this->callbackAnswer()['show_alert']);
        // Boshqa o'qituvchi bu guruhni ko'rmaydi.
        $other = User::factory()->role(Role::Teacher)->create();
        DB::table('telegram_links')->insert(['user_id' => $other->id, 'telegram_id' => 104, 'chat_id' => 104, 'linked_at' => now()]);
        $this->press(104, "a:m:$sid:{$this->student->id}:P");
        $this->assertTrue($this->callbackAnswer()['show_alert']);
        // Kelajakdagi dars — controller'ning xabari ko'rsatiladi.
        $this->press(101, 'a:m:'.$this->sessionId('2026-10-28 14:00:00').":{$this->student->id}:P");
        $this->assertSame('Dars hali boshlanmagan.', $this->callbackAnswer()['text']);
        $this->assertSame(0, DB::table('attendances')->count());

        $this->text(102, '/today');
        $this->assertStringContainsString('mavjud emas', $this->last(102)['text']);
    }

    // ── Admin ────────────────────────────────────────────────

    public function test_admin_commands_only_from_admin_chats(): void
    {
        foreach (['/admin', '/stats', '/find 998', '/broadcast'] as $cmd) {
            $this->text(102, $cmd);
            $this->assertSame('Bu buyruq faqat administratorlar uchun.', $this->last(102)['text']);
        }
        $this->press(102, 'adm:stats');
        $this->assertTrue($this->callbackAnswer()['show_alert']);
        $this->assertSame(0, collect(Http::recorded())->filter(fn ($p) => str_contains((string) ($p[0]['text'] ?? ''), 'Statistika'))->count());

        app(Settings::class)->set(['telegram.admin_chat_ids' => [self::ADMIN_CHAT]], null);
        $this->text(self::ADMIN_CHAT, '/stats');
        $this->assertStringContainsString('Foydalanuvchilar: 4', $this->last(self::ADMIN_CHAT)['text']);
        $this->assertStringContainsString('Bugungi darslar: 1', $this->last(self::ADMIN_CHAT)['text']);

        // Admin tugmasi admin chatida ko'rinadi.
        $this->text(self::ADMIN_CHAT, '/menu');
        $this->assertContains('🛠 Admin panel', collect($this->last(self::ADMIN_CHAT)['reply_markup']['keyboard'])->flatten(1)->pluck('text'));

        // Chat ro'yxatda bo'lsa ham, bog'langan akkauntda user.manage bo'lmasa — admin emas.
        DB::table('telegram_links')->where('telegram_id', self::ADMIN_CHAT)->delete();
        DB::table('telegram_links')->where('user_id', $this->teacher->id)->update(['telegram_id' => self::ADMIN_CHAT, 'chat_id' => self::ADMIN_CHAT]);
        $this->text(self::ADMIN_CHAT, '/menu');
        $this->assertNotContains('🛠 Admin panel', collect($this->last(self::ADMIN_CHAT)['reply_markup']['keyboard'] ?? [])->flatten(1)->pluck('text'));
    }

    public function test_admin_search_by_phone(): void
    {
        app(Settings::class)->set(['telegram.admin_chat_ids' => [self::ADMIN_CHAT]], null);
        $this->student->update(['phone' => '+998901234567']);
        $this->press(self::ADMIN_CHAT, 'adm:find');
        $this->text(self::ADMIN_CHAT, '1234567');
        $this->assertStringContainsString('Aziz K', $this->last(self::ADMIN_CHAT)['text']);
        $this->text(self::ADMIN_CHAT, '/find 000000000');
        $this->assertSame('Hech kim topilmadi.', $this->last(self::ADMIN_CHAT)['text']);
    }

    public function test_new_registration_notifies_admins(): void
    {
        app(Settings::class)->set(['telegram.admin_chat_ids' => [self::ADMIN_CHAT]], null);
        $this->postJson('/v1/telegram/webhook', ['update_id' => 3, 'message' => [
            'message_id' => 1, 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'first_name' => 'Yangi', 'username' => 'yangi_u'],
            'contact' => ['phone_number' => '998935550000', 'user_id' => 777, 'first_name' => 'Yangi'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === self::ADMIN_CHAT
            && str_contains((string) $r['text'], '+998935550000') && str_contains((string) $r['text'], '@yangi_u'));
        // Yangi foydalanuvchiga menyu klaviaturasi berildi.
        $this->assertArrayHasKey('keyboard', $this->last(777)['reply_markup']);
    }

    public function test_broadcast_requires_confirmation_and_is_logged(): void
    {
        app(Settings::class)->set(['telegram.admin_chat_ids' => [self::ADMIN_CHAT]], null);
        $second = User::factory()->create();
        DB::table('telegram_links')->insert(['user_id' => $second->id, 'telegram_id' => 105, 'chat_id' => 105, 'linked_at' => now()]);
        User::factory()->create(['active' => false]); // ulanmagan / faol emas — yuborilmaydi

        $this->press(self::ADMIN_CHAT, 'adm:bc');
        $this->assertSame('adm:to:STUDENT', $this->last(self::ADMIN_CHAT)['reply_markup']['inline_keyboard'][1][0]['callback_data']);
        $this->press(self::ADMIN_CHAT, 'adm:to:STUDENT');
        $this->text(self::ADMIN_CHAT, 'Ertaga dars yo\'q!');
        $preview = $this->last(self::ADMIN_CHAT);
        $this->assertStringContainsString('2 kishi', $preview['text']);
        $confirm = $preview['reply_markup']['inline_keyboard'][0][0]['callback_data'];
        $this->assertStringStartsWith('adm:ok:', $confirm);
        // Tasdiqlanmaguncha hech kimga yuborilmagan.
        Http::assertNotSent(fn ($r) => in_array((int) ($r['chat_id'] ?? 0), [102, 105], true) && ($r['text'] ?? '') === 'Ertaga dars yo\'q!');

        // Admin bo'lmagan chat tasdiqlay olmaydi.
        $this->press(102, $confirm);
        $this->assertSame(0, DB::table('notifications_log')->where('kind', 'broadcast')->count());

        $this->press(self::ADMIN_CHAT, $confirm);
        foreach ([102, 105] as $chat) {
            Http::assertSent(fn ($r) => (int) ($r['chat_id'] ?? 0) === $chat && ($r['text'] ?? '') === 'Ertaga dars yo\'q!');
        }
        Http::assertNotSent(fn ($r) => in_array((int) ($r['chat_id'] ?? 0), [101, 103], true) && ($r['text'] ?? '') === 'Ertaga dars yo\'q!');
        $logs = DB::table('notifications_log')->where('kind', 'broadcast')->get();
        $this->assertEqualsCanonicalizing([$this->student->id, $second->id], $logs->pluck('user_id')->all());
        $this->assertTrue($logs->every(fn ($l) => $l->sent_at !== null && $l->error === null));
        $this->assertStringContainsString('Yuborildi: 2', $this->last(self::ADMIN_CHAT)['text']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'telegram.broadcast')->count());

        // Qayta bosish — qoralama ishlatilgan.
        $this->press(self::ADMIN_CHAT, $confirm);
        $this->assertSame(2, DB::table('notifications_log')->where('kind', 'broadcast')->count());
    }

    public function test_broadcast_can_be_cancelled(): void
    {
        app(Settings::class)->set(['telegram.admin_chat_ids' => [self::ADMIN_CHAT]], null);
        $this->press(self::ADMIN_CHAT, 'adm:to:all');
        $this->text(self::ADMIN_CHAT, '/cancel');
        $this->assertSame('Bekor qilindi.', $this->last(self::ADMIN_CHAT)['text']);
        $this->text(self::ADMIN_CHAT, 'Salom hammaga');
        $this->assertStringNotContainsString('Tasdiqlaysizmi', $this->last(self::ADMIN_CHAT)['text']);

        $this->press(self::ADMIN_CHAT, 'adm:to:all');
        $this->text(self::ADMIN_CHAT, 'Salom hammaga');
        $this->press(self::ADMIN_CHAT, 'adm:no');
        $this->assertSame(0, DB::table('notifications_log')->where('kind', 'broadcast')->count());
    }
}
