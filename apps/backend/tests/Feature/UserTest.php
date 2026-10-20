<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_teacher_but_not_super_admin(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

        $this->postJson('/v1/users', ['phone' => '+998901112233', 'first_name' => 'Ali', 'role' => 'TEACHER', 'password' => 'long-password-1'])
            ->assertCreated()->assertJsonPath('user.role', 'TEACHER');
        $this->postJson('/v1/users', ['phone' => '+998901112234', 'first_name' => 'Vali', 'role' => 'SUPER_ADMIN'])
            ->assertForbidden();
        $this->postJson('/v1/users', ['phone' => '998901112235', 'first_name' => 'X', 'role' => 'STUDENT'])
            ->assertUnprocessable();
        $this->getJson('/v1/users?role=TEACHER')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_teacher_cannot_manage_users(): void
    {
        Sanctum::actingAs(User::factory()->role(Role::Teacher)->create());
        $this->getJson('/v1/users')->assertForbidden();
    }

    public function test_deactivating_user_revokes_tokens(): void
    {
        $student = User::factory()->create();
        $student->createToken('t');
        Sanctum::actingAs(User::factory()->role(Role::Admin)->create());

        $this->patchJson("/v1/users/{$student->id}", ['active' => false])->assertOk()->assertJsonPath('user.active', false);
        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_change_own_password(): void
    {
        $u = User::factory()->create(['password' => 'old-password-1']);
        $token = $u->createToken('a')->plainTextToken;
        $u->createToken('b');

        $this->withToken($token)->postJson('/v1/auth/password', ['current_password' => 'wrong', 'new_password' => 'new-password-1'])
            ->assertUnprocessable();
        $this->withToken($token)->postJson('/v1/auth/password', ['current_password' => 'old-password-1', 'new_password' => 'new-password-1'])
            ->assertOk();
        $this->assertSame(1, $u->tokens()->count());
    }
}
