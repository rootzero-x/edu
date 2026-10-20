<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Markaz faqat o'quvchilar bilan ishlaydi: PARENT roli va ota-ona ↔ farzand bog'lanishi yo'q. */
class ParentRoleRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_is_not_a_role(): void
    {
        $this->assertNull(Role::tryFrom('PARENT'));
        $this->assertSame(['SUPER_ADMIN', 'ADMIN', 'TEACHER', 'STUDENT'], array_map(fn (Role $r) => $r->value, Role::cases()));
    }

    public function test_parent_role_value_is_rejected_by_the_api(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());

        $this->postJson('/v1/users', ['phone' => '+998901112233', 'first_name' => 'X', 'role' => 'PARENT'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson("/v1/users/{$student->id}", ['role' => 'PARENT'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->getJson('/v1/users?role=PARENT')->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/v1/announcements', ['title' => 'T', 'body' => 'B', 'audience' => 'ROLE', 'audience_role' => 'PARENT'])
            ->assertUnprocessable()->assertJsonValidationErrors('audience_role');
        $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => ['PARENT' => ['course.view']]]])
            ->assertUnprocessable();
        $this->assertSame(Role::Student, $student->fresh()->role);
    }

    public function test_no_route_exposes_children_endpoints(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertEmpty($uris->filter(fn ($u) => preg_match('/child|parent/i', $u))->all());
        $this->assertFalse(Schema::hasTable('parent_child'));

        $user = User::factory()->create();
        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());
        $this->getJson("/v1/users/{$user->id}/children")->assertNotFound();
        $this->postJson("/v1/users/{$user->id}/children", ['child_id' => $user->id])->assertNotFound();
    }

    public function test_removed_parent_settings_are_gone_and_stale_stored_values_do_not_break(): void
    {
        foreach (['attendance.notify_parents', 'billing.notify_parents', 'automation.lesson_reminder_parents', 'telegram.text.btn_children'] as $key) {
            $this->assertArrayNotHasKey($key, Settings::definitions());
        }
        foreach (['roles.permissions', 'telegram.menu', 'telegram.role_labels', 'telegram.text.admin_audience_labels'] as $key) {
            $this->assertArrayNotHasKey('PARENT', (array) Settings::definitions()[$key]['default']);
        }

        // Eski versiyadan qolgan qiymatlar: endi noma'lum kalit va PARENT roli.
        DB::table('settings')->insert([
            ['key' => 'billing.notify_parents', 'value' => 'true', 'updated_at' => now()],
            ['key' => 'roles.permissions', 'value' => json_encode(['STUDENT' => ['course.view', 'quiz.take'], 'PARENT' => ['course.view']]), 'updated_at' => now()],
        ]);
        Cache::flush();
        $settings = app(Settings::class);
        $this->assertArrayNotHasKey('billing.notify_parents', $settings->all());
        $settings->set(['roles.permissions' => ['STUDENT' => ['course.view']]], null);
        $this->assertArrayNotHasKey('PARENT', $settings->get('roles.permissions'));

        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());
        $this->getJson('/v1/settings')->assertOk();
        $this->getJson('/v1/public/settings')->assertOk();
    }

    public function test_migration_converts_parents_and_cleans_stored_settings(): void
    {
        $id = DB::table('users')->insertGetId(['phone' => '+998901110000', 'first_name' => 'P', 'role' => 'PARENT', 'active' => true,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('settings')->insert([
            ['key' => 'attendance.notify_parents', 'value' => 'true', 'updated_at' => now()],
            ['key' => 'telegram.menu', 'value' => json_encode(['STUDENT' => [['courses', 'children'], ['children']], 'PARENT' => [['children']]]), 'updated_at' => now()],
            ['key' => 'miniapp.texts', 'value' => json_encode(['nav_children' => 'F', 'logout' => 'Chiqish']), 'updated_at' => now()],
            ['key' => 'telegram.profile.commands', 'value' => json_encode(['default' => [['command' => 'children', 'description' => 'F'], ['command' => 'menu', 'description' => 'M']]]), 'updated_at' => now()],
        ]);

        (require database_path('migrations/2026_10_23_000002_remove_parent_role.php'))->up();

        $this->assertDatabaseHas('users', ['id' => $id, 'role' => 'STUDENT', 'active' => false]);
        $this->assertDatabaseMissing('settings', ['key' => 'attendance.notify_parents']);
        $stored = DB::table('settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true));
        $this->assertSame(['STUDENT' => [['courses']]], $stored['telegram.menu']);
        $this->assertSame(['logout' => 'Chiqish'], $stored['miniapp.texts']);
        $this->assertSame(['default' => [['command' => 'menu', 'description' => 'M']]], $stored['telegram.profile.commands']);
    }
}
