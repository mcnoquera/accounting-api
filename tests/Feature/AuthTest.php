<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_logs_event(): void
    {
        $user = User::factory()->role(Role::Auditor)->create(['email' => 'a@test.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@test.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', 'auditor')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'login', 'user_id' => $user->id]);
    }

    public function test_bad_credentials_fail_and_are_audited(): void
    {
        User::factory()->create(['email' => 'a@test.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@test.com', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'login_failed', 'user_id' => null]);
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/accounts')->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_me_returns_current_user(): void
    {
        $user = $this->actingAsRole(Role::Accountant);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }
}
