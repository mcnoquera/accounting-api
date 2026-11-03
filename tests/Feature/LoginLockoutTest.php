<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => 'lock@test.com', 'password' => $password]);
    }

    public function test_five_failed_sign_ins_lock_the_account(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::factory()->create(['email' => 'lock@test.com']);

        for ($i = 0; $i < 4; $i++) {
            $this->attempt('wrong')->assertStatus(422);
        }
        $this->assertSame(4, $user->refresh()->failed_login_attempts);
        $this->assertFalse($user->isLocked());

        $this->attempt('wrong')->assertStatus(422);
        $this->assertTrue($user->refresh()->isLocked());
        $this->assertDatabaseHas('audit_logs', ['action' => 'locked_out', 'auditable_id' => $user->id]);

        // Even the right password is refused while locked.
        $this->attempt('password')->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Too many failed sign-ins. This account is locked for 15 more minute(s).');

        $this->travel(User::LOCKOUT_MINUTES + 1)->minutes();
        $this->attempt('password')->assertOk();
        $this->assertSame(0, $user->refresh()->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    public function test_successful_sign_in_resets_the_counter(): void
    {
        $user = User::factory()->create(['email' => 'lock@test.com']);
        $this->attempt('wrong');
        $this->attempt('wrong');
        $this->attempt('password')->assertOk();

        $this->assertSame(0, $user->refresh()->failed_login_attempts);
    }

    public function test_admin_can_unlock_an_account(): void
    {
        $user = User::factory()->create(['email' => 'lock@test.com', 'locked_until' => now()->addMinutes(10)]);
        $this->actingAsRole(Role::Admin);

        $listed = collect($this->getJson('/api/v1/users')->json('data'))->firstWhere('id', $user->id);
        $this->assertTrue($listed['is_locked']);
        $this->postJson("/api/v1/users/{$user->id}/unlock")->assertOk()->assertJsonPath('data.is_locked', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'unlocked', 'auditable_id' => $user->id]);
    }

    public function test_lockout_is_not_written_as_a_user_change(): void
    {
        User::factory()->create(['email' => 'lock@test.com']);
        $this->attempt('wrong');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'updated', 'auditable_type' => 'User']);
    }
}
