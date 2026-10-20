<?php

namespace Tests\Feature;

use App\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_reports_db_ok(): void
    {
        $this->getJson('/health')->assertOk()->assertJson(['status' => 'ok', 'db' => 'ok']);
    }

    public function test_login_me_logout(): void
    {
        User::factory()->role(Role::Teacher)->create(['phone' => '+998901234567', 'password' => 'secret-password']);

        $token = $this->postJson('/v1/auth/login', ['phone' => '+998901234567', 'password' => 'secret-password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'TEACHER')
            ->json('token');

        $this->withToken($token)->getJson('/v1/auth/me')->assertOk()->assertJsonPath('user.phone', '+998901234567');
        $this->withToken($token)->postJson('/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/v1/auth/me')->assertUnauthorized();
    }

    public function test_wrong_password_and_inactive_user_rejected(): void
    {
        User::factory()->create(['phone' => '+998900000001', 'password' => 'right-password']);
        User::factory()->create(['phone' => '+998900000002', 'password' => 'right-password', 'active' => false]);

        $this->postJson('/v1/auth/login', ['phone' => '+998900000001', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/v1/auth/login', ['phone' => '+998900000002', 'password' => 'right-password'])->assertUnprocessable();
    }

    public function test_me_requires_token(): void
    {
        $this->getJson('/v1/auth/me')->assertUnauthorized();
        // Accept: application/json yuborilmasa ham 401 JSON (redirect emas).
        $this->get('/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }
}
