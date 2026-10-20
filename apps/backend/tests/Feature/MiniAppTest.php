<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\Branch;
use App\Models\Course;
use App\Models\User;
use App\Support\Settings;
use App\Telegram\InitData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Telegram Mini App uchun API: kirish (initData) va bir nechta guruh darslari bitta so'rovda. */
class MiniAppTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:TEST-token-for-unit-tests';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 08:00:00'); // chorshanba
        config(['services.telegram.bot_token' => self::TOKEN]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
    }

    private function initData(int $tgId, int $authDate): string
    {
        $fields = ['auth_date' => (string) $authDate, 'query_id' => 'AAA', 'user' => json_encode(['id' => $tgId, 'first_name' => "O‘tkir"])];
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));

        return http_build_query($fields + ['hash' => hash_hmac('sha256', $check, hash_hmac('sha256', self::TOKEN, 'WebAppData', true))]);
    }

    public function test_expired_init_data_is_reported_separately_from_invalid(): void
    {
        $user = User::factory()->create();
        DB::table('telegram_links')->insert(['user_id' => $user->id, 'telegram_id' => 4242, 'chat_id' => 4242, 'linked_at' => now()]);

        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $this->initData(4242, time())])
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('user.id', $user->id);
        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $this->initData(4242, time() - 7200)])
            ->assertUnauthorized()->assertJsonPath('status', 'expired');
        $forged = str_replace('hash=', 'hash=0', $this->initData(4242, time()));
        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $forged])
            ->assertUnauthorized()->assertJsonPath('status', 'invalid');
        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $this->initData(9999, time())])
            ->assertNotFound()->assertJsonPath('status', 'need_phone');
    }

    public function test_init_data_inspect_tolerates_small_clock_skew(): void
    {
        $now = time();
        $this->assertSame('ok', InitData::inspect($this->initData(1, $now + 60), self::TOKEN, 3600, $now)[1]);
        $this->assertSame('invalid', InitData::inspect($this->initData(1, $now + 3600), self::TOKEN, 3600, $now)[1]);
        $this->assertSame('expired', InitData::inspect($this->initData(1, $now - 3601), self::TOKEN, 3600, $now)[1]);
        $this->assertSame('invalid', InitData::inspect($this->initData(1, $now), '', 3600, $now)[1]);
        $this->assertSame(1, InitData::unverifiedUserId($this->initData(1, $now)));
        $this->assertNull(InitData::unverifiedUserId('user=%7Bbad'));
    }

    public function test_login_throttle_is_per_telegram_user_not_per_proxy_ip(): void
    {
        app(Settings::class)->set(['security.login_per_minute' => 2], null);
        // Hammasi bitta IP'dan (Next serveri) keladi: bir foydalanuvchi limitga yetsa, boshqasi kira oladi.
        foreach (range(1, 2) as $_) {
            $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $this->initData(1001, time())])->assertNotFound();
        }
        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $this->initData(1001, time())])->assertStatus(429);
        $this->postJson('/v1/auth/telegram/miniapp', ['init_data' => $this->initData(1002, time())])->assertNotFound();
    }

    public function test_sessions_across_groups_in_one_request_respect_visibility(): void
    {
        $admin = User::factory()->role(Role::Admin)->create();
        $t1 = User::factory()->role(Role::Teacher)->create();
        $t2 = User::factory()->role(Role::Teacher)->create();
        $student = User::factory()->create();
        $branch = Branch::create(['name' => 'Markaz']);
        $course = Course::create(['title' => 'Ingliz', 'created_by' => $admin->id]);
        Sanctum::actingAs($admin);
        $mk = fn (string $name, User $t) => $this->postJson('/v1/groups', [
            'name' => $name, 'course_id' => $course->id, 'branch_id' => $branch->id, 'teacher_id' => $t->id, 'start_date' => '2026-10-19',
            'schedules' => [['weekday' => 3, 'start_time' => '14:00', 'end_time' => '15:30'], ['weekday' => 4, 'start_time' => '14:00', 'end_time' => '15:30']],
        ])->assertCreated()->json('group.id');
        $g1 = $mk('G1', $t1);
        $g2 = $mk('G2', $t2);
        $this->postJson("/v1/groups/$g1/members", ['student_id' => $student->id])->assertCreated();
        foreach ([$g1, $g2] as $g) {
            $this->postJson("/v1/groups/$g/sessions/generate", ['from' => '2026-10-19', 'to' => '2026-10-25'])->assertOk();
        }

        $all = $this->getJson('/v1/sessions?from=2026-10-21&to=2026-10-22')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$g1, $g1, $g2, $g2], array_column($all, 'group_id'));
        $this->assertArrayHasKey('marked_count', $all[0]);
        $only = $this->getJson("/v1/sessions?from=2026-10-21&to=2026-10-21&group_ids=$g2")->assertOk()->json('data');
        $this->assertSame([$g2], array_column($only, 'group_id'));

        Sanctum::actingAs($t1);
        $this->assertSame([$g1, $g1], array_column($this->getJson('/v1/sessions?from=2026-10-21&to=2026-10-22')->json('data'), 'group_id'));
        // Boshqa o'qituvchining guruhini so'rasa ham — ko'rinmaydi.
        $this->assertSame([], $this->getJson("/v1/sessions?from=2026-10-21&to=2026-10-22&group_ids=$g2")->json('data'));

        Sanctum::actingAs($student);
        $this->assertSame([$g1, $g1], array_column($this->getJson('/v1/sessions?from=2026-10-21&to=2026-10-22')->json('data'), 'group_id'));

        $this->getJson('/v1/sessions?from=2026-10-21&to=2027-10-21')->assertStatus(422);
        $this->getJson('/v1/sessions?from=2026-10-21&to=2026-10-20')->assertStatus(422);
        $this->getJson('/v1/sessions?from=2026-10-21&to=2026-10-22&group_ids=1;drop')->assertStatus(422);
    }
}
