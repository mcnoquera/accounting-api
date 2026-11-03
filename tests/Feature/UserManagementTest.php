<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_user_without_exposing_secrets(): void
    {
        $this->actingAsRole(Role::Admin);

        $this->postJson('/api/v1/users', [
            'name' => 'Bea Bookkeeper', 'email' => 'bea@test.com', 'role' => 'accountant', 'password' => 'a-long-password',
        ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'accountant')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password');

        $log = AuditLog::where('action', 'created')->where('auditable_type', 'User')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('password', $log->new_values);

        $this->postJson('/api/v1/auth/login', ['email' => 'bea@test.com', 'password' => 'a-long-password'])->assertOk();
    }

    public function test_user_validation(): void
    {
        $this->actingAsRole(Role::Admin);
        User::factory()->create(['email' => 'taken@test.com']);

        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'taken@test.com', 'role' => 'boss', 'password' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'role', 'password']);
    }

    public function test_only_admins_manage_users(): void
    {
        foreach ([Role::Accountant, Role::Auditor] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/users')->assertForbidden();
            $this->postJson('/api/v1/users', [])->assertForbidden();
        }
    }

    public function test_admin_changes_role_and_lists_users(): void
    {
        $this->actingAsRole(Role::Admin);
        $user = User::factory()->role(Role::Accountant)->create();

        $this->putJson("/api/v1/users/{$user->id}", ['role' => 'auditor'])->assertOk()->assertJsonPath('data.role', 'auditor');
        $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'updated', 'auditable_type' => 'User', 'auditable_id' => $user->id]);
    }

    public function test_deactivated_user_loses_tokens_and_cannot_sign_in(): void
    {
        $this->actingAsRole(Role::Admin);
        $user = User::factory()->create(['email' => 'gone@test.com']);
        $user->createToken('web');

        $this->putJson("/api/v1/users/{$user->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/v1/auth/login', ['email' => 'gone@test.com', 'password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This account has been deactivated. Contact your administrator.');
    }

    public function test_last_active_admin_cannot_be_demoted_or_deactivated(): void
    {
        $admin = $this->actingAsRole(Role::Admin);
        $other = User::factory()->role(Role::Admin)->create(['is_active' => false]);

        $this->putJson("/api/v1/users/{$admin->id}", ['role' => 'accountant'])
            ->assertStatus(422)->assertJsonPath('message', 'There must be at least one active admin.');

        // An inactive admin doesn't count; once it is active, the demotion is allowed.
        $other->update(['is_active' => true]);
        $this->putJson("/api/v1/users/{$admin->id}", ['role' => 'accountant'])->assertOk();

        Sanctum::actingAs($other);
        $this->putJson("/api/v1/users/{$other->id}", ['role' => 'auditor'])->assertStatus(422);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->actingAsRole(Role::Admin);
        User::factory()->role(Role::Admin)->create();

        $this->putJson("/api/v1/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors(['is_active']);
    }

    public function test_users_are_never_deleted(): void
    {
        $this->actingAsRole(Role::Admin);
        $user = User::factory()->create();

        $this->deleteJson("/api/v1/users/{$user->id}")->assertStatus(405);
    }
}
