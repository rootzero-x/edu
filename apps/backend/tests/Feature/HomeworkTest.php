<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Course;
use App\Models\User;
use App\Support\Settings;
use App\Support\Uploads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Sprint 3 #12: uyga vazifa, topshirish, baholash, mashg'ulot baholari va eslatmalar. */
class HomeworkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $otherTeacher;

    private User $student;

    private User $student2;

    private User $outsider;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 16:00:00');
        Storage::fake(Uploads::DISK);
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create(['first_name' => 'Olim']);
        $this->otherTeacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create(['first_name' => 'Aziz']);
        $this->student2 = User::factory()->create(['first_name' => 'Bobur']);
        $this->outsider = User::factory()->create();
        foreach ([$this->student, $this->teacher] as $i => $u) {
            DB::table('telegram_links')->insert(['user_id' => $u->id, 'telegram_id' => 100 + $i, 'chat_id' => 100 + $i, 'linked_at' => now()]);
        }
        $course = Course::create(['title' => 'Ingliz', 'created_by' => $this->admin->id]);
        Sanctum::actingAs($this->admin);
        $this->groupId = $this->postJson('/v1/groups', [
            'name' => 'ENG-1', 'course_id' => $course->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01',
            'schedules' => [['weekday' => 3, 'start_time' => '14:00', 'end_time' => '15:30']],
        ])->assertCreated()->json('group.id');
        foreach ([$this->student, $this->student2] as $s) {
            $this->postJson("/v1/groups/{$this->groupId}/members", ['student_id' => $s->id])->assertCreated();
        }
        $this->postJson("/v1/groups/{$this->groupId}/sessions/generate", ['from' => '2026-10-21', 'to' => '2026-10-21']);
    }

    private function sessionId(): int
    {
        return (int) DB::table('class_sessions')->where('group_id', $this->groupId)->value('id');
    }

    private function assign(array $over = []): int
    {
        Sanctum::actingAs($this->teacher);

        return $this->postJson("/v1/groups/{$this->groupId}/assignments", $over + [
            'title' => '1-10 mashqlar', 'body' => 'Kitobdan', 'due_at' => '2026-10-23T18:00:00+05:00', 'session_id' => $this->sessionId(),
        ])->assertCreated()->json('assignment.id');
    }

    public function test_teacher_assigns_and_students_are_notified(): void
    {
        $id = $this->assign();
        $this->assertDatabaseHas('notifications_log', ['user_id' => $this->student->id, 'kind' => "homework.new:$id"]);
        $this->assertNotNull(DB::table('notifications_log')->where(['user_id' => $this->student->id, 'kind' => "homework.new:$id"])->value('sent_at'));
        // Telegram ulanmagan o'quvchi ham qo'ng'iroqchada ko'radi (yozuv bor, xato — not linked).
        $this->assertSame('telegram_not_linked', DB::table('notifications_log')->where(['user_id' => $this->student2->id, 'kind' => "homework.new:$id"])->value('error'));
        $this->assertDatabaseHas('assignments', ['id' => $id, 'due_at' => '2026-10-23 18:00:00']);

        Sanctum::actingAs($this->otherTeacher);
        $this->postJson("/v1/groups/{$this->groupId}/assignments", ['title' => 'x'])->assertForbidden();
        $this->patchJson("/v1/assignments/$id", ['title' => 'y'])->assertForbidden();
        Sanctum::actingAs($this->outsider);
        $this->getJson("/v1/assignments/$id")->assertNotFound();

        // Boshqa guruh mashg'uloti biriktirilmaydi.
        Sanctum::actingAs($this->teacher);
        $this->postJson("/v1/groups/{$this->groupId}/assignments", ['title' => 'x', 'session_id' => 999999])->assertUnprocessable();
    }

    public function test_student_submits_teacher_grades_and_submission_locks(): void
    {
        $id = $this->assign();
        Sanctum::actingAs($this->student);
        $this->getJson('/v1/assignments')->assertOk()->assertJsonPath('mode', 'student')->assertJsonPath('data.0.my.status', 'PENDING');
        $this->putJson("/v1/assignments/$id/submission", [])->assertUnprocessable();
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'Javoblarim', 'link_url' => 'https://docs.example.com/x'])
            ->assertOk()->assertJsonPath('my.status', 'SUBMITTED');
        // Baholanguncha qayta topshirish mumkin.
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'Tuzatilgan javob'])->assertOk();

        Sanctum::actingAs($this->teacher);
        $show = $this->getJson("/v1/assignments/$id")->assertOk();
        $this->assertTrue($show->json('assignment.can_manage'));
        $this->assertSame(['total' => 2, 'submitted' => 1, 'graded' => 0, 'late' => 0], $show->json('assignment.stats'));
        $rows = collect($show->json('assignment.submissions'))->keyBy('name');
        $this->assertSame('SUBMITTED', $rows[$this->student->first_name.' '.$this->student->last_name]['status']);
        $this->assertSame('Tuzatilgan javob', $rows[$this->student->first_name.' '.$this->student->last_name]['body']);

        $this->putJson("/v1/assignments/$id/grades", ['items' => [['student_id' => $this->student->id, 'score' => 6]]])->assertUnprocessable();
        $this->putJson("/v1/assignments/$id/grades", ['items' => [
            ['student_id' => $this->student->id, 'score' => 5, 'feedback' => "Zo'r!"],
            ['student_id' => $this->student2->id, 'score' => 2, 'feedback' => 'Topshirilmadi'],
        ]])->assertOk()->assertJsonPath('changed', 2);
        $this->putJson("/v1/assignments/$id/grades", ['items' => [['student_id' => $this->outsider->id, 'score' => 3]]])->assertUnprocessable();
        $this->assertDatabaseHas('notifications_log', ['user_id' => $this->student->id, 'kind' => "homework.graded:$id"]);
        $log = json_decode(DB::table('audit_logs')->where('action', 'assignment.grade')->value('data'), true);
        $this->assertCount(2, $log['changes']);

        Sanctum::actingAs($this->student);
        $this->getJson("/v1/assignments/$id")->assertOk()->assertJsonPath('assignment.my.status', 'GRADED')
            ->assertJsonPath('assignment.my.score', 5)->assertJsonPath('assignment.my.can_submit', false);
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'yana'])->assertStatus(409);
        $this->getJson('/v1/assignments?status=open')->assertOk()->assertJsonCount(0, 'data');

        // Javob yoki baho bor vazifa o'chirilmaydi.
        Sanctum::actingAs($this->teacher);
        $this->deleteJson("/v1/assignments/$id")->assertStatus(409);
    }

    public function test_late_and_missed_statuses_and_allow_late_setting(): void
    {
        $id = $this->assign(['due_at' => '2026-10-21T18:00:00+05:00']);
        $this->travelTo(Carbon::parse('2026-10-22 09:00:00'));
        Sanctum::actingAs($this->student2);
        $this->getJson("/v1/assignments/$id")->assertJsonPath('assignment.my.status', 'MISSED');
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'kech'])->assertOk()->assertJsonPath('my.status', 'LATE');

        app(Settings::class)->set(['homework.allow_late' => false], null);
        Sanctum::actingAs($this->student);
        $this->getJson("/v1/assignments/$id")->assertJsonPath('assignment.my.can_submit', false);
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'kech'])->assertUnprocessable();

        Sanctum::actingAs($this->outsider);
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'x'])->assertForbidden();
    }

    public function test_files_on_assignment_and_submission_are_access_controlled(): void
    {
        $id = $this->assign();
        Sanctum::actingAs($this->teacher);
        $tf = $this->post("/v1/assignments/$id/files", ['file' => UploadedFile::fake()->createWithContent('topshiriq.pdf', UploadsTest::pdf())], ['Accept' => 'application/json'])
            ->assertCreated()->json('file.id');

        Sanctum::actingAs($this->student);
        $this->getJson("/v1/files/$tf/link")->assertOk();
        $sf = $this->post("/v1/assignments/$id/submission/files", ['file' => UploadedFile::fake()->createWithContent('javob.png', UploadsTest::png())], ['Accept' => 'application/json'])
            ->assertCreated()->json('file.id');
        // Fayl yuklangan, lekin hali "Topshirish" bosilmagan — holat PENDING; faqat fayl bilan topshirish mumkin.
        $this->getJson("/v1/assignments/$id")->assertJsonPath('assignment.my.status', 'PENDING')->assertJsonCount(1, 'assignment.my.files');
        $this->putJson("/v1/assignments/$id/submission", [])->assertOk()->assertJsonPath('my.status', 'SUBMITTED');

        Sanctum::actingAs($this->student2);
        $this->getJson("/v1/files/$sf/link")->assertNotFound();
        $this->getJson("/v1/files/$tf/link")->assertOk();
        Sanctum::actingAs($this->outsider);
        $this->getJson("/v1/files/$tf/link")->assertNotFound();
        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/files/$sf/link")->assertOk();

        app(Settings::class)->set(['uploads.max_files_per_item' => 1], null);
        Sanctum::actingAs($this->student);
        $this->post("/v1/assignments/$id/submission/files", ['file' => UploadedFile::fake()->createWithContent('b.png', UploadsTest::png())], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    public function test_assignment_without_answers_can_be_deleted_with_its_files(): void
    {
        $id = $this->assign();
        Sanctum::actingAs($this->teacher);
        $this->post("/v1/assignments/$id/files", ['file' => UploadedFile::fake()->createWithContent('a.pdf', UploadsTest::pdf())], ['Accept' => 'application/json'])->assertCreated();
        $this->deleteJson("/v1/assignments/$id")->assertOk();
        $this->assertSame(0, DB::table('files')->count());
        $this->assertSame([], Storage::disk(Uploads::DISK)->allFiles());
    }

    public function test_session_marks_from_the_sheet_and_my_marks(): void
    {
        $sid = $this->sessionId();
        Sanctum::actingAs($this->teacher);
        $this->getJson("/v1/sessions/$sid/marks")->assertOk()->assertJsonPath('can_edit', true)->assertJsonPath('scale.max', 5)->assertJsonCount(2, 'rows');
        $this->putJson("/v1/sessions/$sid/marks", ['items' => [['student_id' => $this->student->id, 'score' => 9]]])->assertUnprocessable();
        $this->putJson("/v1/sessions/$sid/marks", ['items' => [['student_id' => $this->outsider->id, 'score' => 4]]])->assertUnprocessable();
        $this->putJson("/v1/sessions/$sid/marks", ['items' => [
            ['student_id' => $this->student->id, 'score' => 4, 'note' => 'Faol'], ['student_id' => $this->student2->id, 'score' => 3],
        ]])->assertOk()->assertJsonPath('changed', 2);
        $this->assertDatabaseHas('notifications_log', ['user_id' => $this->student->id, 'kind' => "mark:$sid"]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'marks.update', 'entity_id' => $sid]);
        // null — bahoni olib tashlash.
        $this->putJson("/v1/sessions/$sid/marks", ['items' => [['student_id' => $this->student2->id, 'score' => null]]])->assertOk()->assertJsonPath('changed', 1);

        Sanctum::actingAs($this->otherTeacher);
        $this->putJson("/v1/sessions/$sid/marks", ['items' => [['student_id' => $this->student->id, 'score' => 5]]])->assertNotFound();
        Sanctum::actingAs($this->student);
        $this->putJson("/v1/sessions/$sid/marks", ['items' => [['student_id' => $this->student->id, 'score' => 5]]])->assertForbidden();
        $own = $this->getJson("/v1/sessions/$sid/marks")->assertOk()->assertJsonPath('can_edit', false);
        $this->assertCount(1, $own->json('rows'));

        $id = $this->assign(['max_score' => 10]);
        Sanctum::actingAs($this->teacher);
        $this->putJson("/v1/assignments/$id/grades", ['items' => [['student_id' => $this->student->id, 'score' => 7]]])->assertOk();
        Sanctum::actingAs($this->student);
        $m = $this->getJson('/v1/marks/me')->assertOk();
        $this->assertCount(2, $m->json('data'));
        $this->assertEqualsCanonicalizing([80, 70], array_column($m->json('data'), 'percent'));
        $this->assertEquals(75, $m->json('average_percent'));

        // Tahrir muddati (o'qituvchi uchun) o'tsa — baho qo'yib bo'lmaydi.
        app(Settings::class)->set(['attendance.teacher_edit_days' => 3], null);
        $this->travel(5)->days();
        Sanctum::actingAs($this->teacher);
        $this->putJson("/v1/sessions/$sid/marks", ['items' => [['student_id' => $this->student->id, 'score' => 5]]])->assertForbidden();
    }

    public function test_due_reminder_and_teacher_summary_are_sent_once(): void
    {
        $id = $this->assign(['due_at' => '2026-10-22T02:00:00+05:00']); // 10 soat qoldi (eslatma: 12 soat oldin)
        Sanctum::actingAs($this->student2);
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'tayyor'])->assertOk();

        $this->artisan('tisu:remind-homework')->assertSuccessful();
        $this->artisan('tisu:remind-homework')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications_log')->where('kind', "homework.due:$id")->count());
        $this->assertDatabaseHas('notifications_log', ['kind' => "homework.due:$id", 'user_id' => $this->student->id]);
        $this->assertSame(0, DB::table('notifications_log')->where('kind', "homework.summary:$id")->count());

        $this->travelTo(Carbon::parse('2026-10-22 09:00:00'));
        $this->artisan('tisu:remind-homework')->assertSuccessful();
        $this->artisan('tisu:remind-homework')->assertSuccessful();
        $summary = DB::table('notifications_log')->where('kind', "homework.summary:$id")->get();
        $this->assertCount(1, $summary);
        $this->assertSame($this->teacher->id, (int) $summary[0]->user_id);
        $this->assertStringContainsString('1/2', json_decode($summary[0]->payload, true)['text']);
    }

    public function test_homework_report_and_calendar_count(): void
    {
        $id = $this->assign(['due_at' => '2026-10-21T18:00:00+05:00']);
        Sanctum::actingAs($this->student);
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'ok'])->assertOk();
        Sanctum::actingAs($this->teacher);
        $this->putJson("/v1/assignments/$id/grades", ['items' => [['student_id' => $this->student->id, 'score' => 4]]])->assertOk();

        $r = $this->getJson('/v1/reports/homework?from=2026-10-01&to=2026-10-31')->assertOk();
        $this->assertSame(1, $r->json('data.0.assignments'));
        $this->assertSame(2, $r->json('data.0.expected'));
        $this->assertSame(1, $r->json('data.0.submitted'));
        $this->assertEquals(50, $r->json('data.0.submission_rate'));
        $this->assertEquals(80, $r->json('data.0.avg_homework_percent'));

        $this->getJson('/v1/calendar?from=2026-10-19&to=2026-10-25')->assertOk()->assertJsonPath('data.0.homework_count', 1);
        $this->getJson('/v1/assignments')->assertOk()->assertJsonPath('mode', 'teacher')->assertJsonPath('data.0.stats.submitted', 1);
    }

    public function test_bot_homework_menu_for_student_and_teacher(): void
    {
        $secret = 'webhook-secret-for-tests-0123456789abcdef';
        config(['services.telegram.webhook_secret' => $secret]);
        $id = $this->assign();
        Sanctum::actingAs($this->student);
        $this->putJson("/v1/assignments/$id/submission", ['body' => 'tayyor'])->assertOk();
        $this->app['auth']->forgetGuards();
        $say = fn (int $chat, string $text) => $this->postJson('/v1/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 1, 'text' => $text, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'X'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => $secret])->assertOk();
        $last = fn (int $chat) => collect(Http::recorded())->map(fn ($p) => $p[0])
            ->filter(fn ($r) => str_ends_with($r->url(), 'sendMessage') && (int) $r['chat_id'] === $chat)->last();

        $say(100, '/start');
        $this->assertContains('📒 Uyga vazifa', collect($last(100)['reply_markup']['keyboard'])->flatten(1)->pluck('text'));
        $say(100, '📒 Uyga vazifa');
        $this->assertStringContainsString('1-10 mashqlar — ENG-1, muddat 23.10 18:00 · Topshirildi', $last(100)['text']);
        $this->assertStringEndsWith('/tg/homework', $last(100)['reply_markup']['inline_keyboard'][0][0]['web_app']['url']);

        $say(101, '/homework');
        $this->assertStringContainsString('1-10 mashqlar — ENG-1, muddat 23.10 18:00 · 1/2', $last(101)['text']);
    }

    public function test_homework_can_be_switched_off(): void
    {
        app(Settings::class)->set(['homework.enabled' => false], null);
        Sanctum::actingAs($this->teacher);
        $this->postJson("/v1/groups/{$this->groupId}/assignments", ['title' => 'x'])->assertNotFound();
        $this->getJson('/v1/assignments')->assertNotFound();
    }
}
