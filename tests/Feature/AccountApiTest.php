<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_accountant_can_create_account(): void
    {
        $this->actingAsRole(Role::Accountant);

        $this->postJson('/api/v1/accounts', ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'])
            ->assertCreated()
            ->assertJsonPath('data.code', '1000')
            ->assertJsonPath('data.type', 'asset');

        $this->assertDatabaseHas('audit_logs', ['action' => 'created', 'auditable_type' => 'Account']);
    }

    public function test_auditor_cannot_modify_accounts(): void
    {
        $this->actingAsRole(Role::Auditor);

        $this->postJson('/api/v1/accounts', ['code' => '1000', 'name' => 'Cash', 'type' => 'asset'])
            ->assertForbidden();
    }

    public function test_validates_type_and_unique_code(): void
    {
        $this->actingAsRole(Role::Admin);
        Account::factory()->create(['code' => '1000']);

        $this->postJson('/api/v1/accounts', ['code' => '1000', 'name' => 'Cash', 'type' => 'gold'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'type']);
    }

    public function test_cannot_delete_account_with_activity(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();
        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '10', 0], [$acc['equity'], 0, '10']]))->assertCreated();

        $this->deleteJson("/api/v1/accounts/{$acc['asset']->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->deleteJson("/api/v1/accounts/{$acc['revenue']->id}")->assertOk();
        $this->assertDatabaseMissing('accounts', ['id' => $acc['revenue']->id]);
    }

    public function test_filter_by_type(): void
    {
        $this->actingAsRole(Role::Auditor);
        $this->basicAccounts();

        $this->getJson('/api/v1/accounts?type=expense')->assertOk()->assertJsonCount(1, 'data');
    }
}
