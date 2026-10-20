<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\StudyGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private User $outsider;

    private StudyGroup $group;

    private StudyGroup $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '1:T']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->teacher = User::factory()->role(Role::Teacher)->create();
        $this->student = User::factory()->create();
        $this->outsider = User::factory()->create();
        $course = Course::create(['title' => 'K', 'created_by' => $this->admin->id]);
        $b = Branch::create(['name' => 'B']);
        $this->group = StudyGroup::create(['name' => 'G1', 'course_id' => $course->id, 'branch_id' => $b->id, 'teacher_id' => $this->teacher->id, 'start_date' => '2026-10-01']);
        $this->other = StudyGroup::create(['name' => 'G2', 'course_id' => $course->id, 'branch_id' => $b->id, 'teacher_id' => $this->admin->id, 'start_date' => '2026-10-01']);
        DB::table('group_members')->insert(['group_id' => $this->group->id, 'student_id' => $this->student->id, 'joined_at' => now()]);
        DB::table('telegram_links')->insert(['user_id' => $this->student->id, 'telegram_id' => 51, 'chat_id' => 51, 'linked_at' => now()]);
        DB::table('telegram_links')->insert(['user_id' => $this->outsider->id, 'telegram_id' => 52, 'chat_id' => 52, 'linked_at' => now()]);
    }

    public function test_group_announcement_reaches_only_group_and_tracks_reads(): void
    {
        Sanctum::actingAs($this->teacher);
        $id = $this->postJson('/v1/announcements', ['title' => 'Dars ko\'chdi', 'body' => 'Ertaga 15:00', 'audience' => 'GROUP',
            'group_id' => $this->group->id, 'notify_telegram' => true, 'publish' => true])->assertCreated()->json('announcement.id');
        // O'qituvchi boshqa guruhga yoki hammaga e'lon bera olmaydi.
        $this->postJson('/v1/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'GROUP', 'group_id' => $this->other->id])->assertForbidden();
        $this->postJson('/v1/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'ALL'])->assertForbidden();

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => (int) $r['chat_id'] === 51 && str_contains($r['text'], "Dars ko'chdi"));

        Sanctum::actingAs($this->student);
        $this->getJson('/v1/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.unread', 1)->assertJsonPath('data.0.read', false);
        $this->postJson("/v1/announcements/$id/read")->assertJsonPath('unread', 0);

        Sanctum::actingAs($this->outsider);
        $this->getJson('/v1/announcements')->assertJsonCount(0, 'data');
        $this->postJson("/v1/announcements/$id/read")->assertNotFound();
        $this->postJson('/v1/announcements', ['title' => 'x', 'body' => 'y', 'audience' => 'ALL'])->assertForbidden();
    }

    public function test_admin_role_audience_drafts_public_and_republish_does_not_resend(): void
    {
        Sanctum::actingAs($this->admin);
        $draft = $this->postJson('/v1/announcements', ['title' => 'Qoralama', 'body' => '...', 'audience' => 'ALL', 'is_public' => true])->json('announcement.id');
        $teachers = $this->postJson('/v1/announcements', ['title' => 'Ustozlarga', 'body' => '...', 'audience' => 'ROLE', 'audience_role' => 'TEACHER', 'publish' => true])->json('announcement.id');

        $this->getJson('/v1/public/announcements')->assertJsonCount(0, 'data'); // qoralama ko'rinmaydi
        $this->postJson("/v1/announcements/$draft/publish", ['published' => true])->assertOk();
        $this->getJson('/v1/public/announcements')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Qoralama');
        $this->getJson('/v1/announcements?scope=manage')->assertJsonCount(2, 'data');

        Sanctum::actingAs($this->student);
        $this->getJson('/v1/announcements')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Qoralama');
        Sanctum::actingAs($this->teacher);
        $this->getJson('/v1/announcements')->assertJsonCount(2, 'data');
        $this->patchJson("/v1/announcements/$teachers", ['title' => 'x'])->assertForbidden(); // muallif emas

        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/v1/announcements', ['title' => 'T', 'body' => 'B', 'audience' => 'ALL', 'notify_telegram' => true, 'publish' => true])->json('announcement.id');
        $sent = count(Http::recorded());
        $this->postJson("/v1/announcements/$id/publish", ['published' => false])->assertOk();
        $this->postJson("/v1/announcements/$id/publish", ['published' => true])->assertJsonPath('notified', 0);
        $this->assertSame($sent, count(Http::recorded()));
        $this->deleteJson("/v1/announcements/$id")->assertOk();
    }
}
