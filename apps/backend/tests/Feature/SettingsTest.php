<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_settings_need_no_login_and_hide_private(): void
    {
        $res = $this->getJson('/v1/public/settings')->assertOk();
        $this->assertArrayHasKey('site.name', $res->json('settings'));
        $this->assertArrayNotHasKey('roles.permissions', $res->json('settings'));
    }

    public function test_super_admin_changes_site_name_and_validation(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());

        $this->patchJson('/v1/settings', ['values' => ['site.name' => 'TISU Akademiya']])->assertOk();
        $this->getJson('/v1/public/settings')->assertJsonPath('settings.site\.name', 'TISU Akademiya');

        $this->patchJson('/v1/settings', ['values' => ['attendance.grace_minutes' => 999]])->assertUnprocessable();
        $this->patchJson('/v1/settings', ['values' => ['nope' => 1]])->assertUnprocessable();
        $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => ['TEACHER' => ['fly']]]])->assertUnprocessable();

        $this->deleteJson('/v1/settings/site.name')->assertOk()->assertJsonPath('value', 'TISU Edu');
    }

    public function test_role_permissions_are_editable_from_outside(): void
    {
        $student = User::factory()->create();
        Sanctum::actingAs($student);
        $this->postJson('/v1/courses', ['title' => 'X'])->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::SuperAdmin)->create());
        $this->patchJson('/v1/settings', ['values' => ['roles.permissions' => [
            'STUDENT' => ['course.view', 'course.manage'],
        ]]])->assertOk();

        Sanctum::actingAs($student);
        $this->postJson('/v1/courses', ['title' => 'X'])->assertCreated();

        // Faqat STUDENT o'zgardi, TEACHER huquqlari saqlanib qoldi.
        Sanctum::actingAs(User::factory()->role(Role::Teacher)->create());
        $this->postJson('/v1/courses', ['title' => 'Y'])->assertCreated();
    }

    public function test_admin_cannot_touch_settings_by_default(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());
        $this->getJson('/v1/settings')->assertForbidden();
    }
}
