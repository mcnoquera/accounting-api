<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Support\DefaultChartOfAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_philippine_default_chart_into_empty_chart(): void
    {
        $this->actingAsRole(Role::Accountant);

        $res = $this->postJson('/api/v1/accounts/load-defaults')->assertCreated();
        $this->assertCount(count(DefaultChartOfAccounts::rows()), $res->json('data'));

        $cashInBank = Account::where('code', '1130')->first();
        $this->assertSame('1100', $cashInBank->parent->code);
        $this->assertSame('debit', $cashInBank->normal_balance);
        $this->assertSame('credit', Account::where('code', '2210')->first()->normal_balance);
        $this->assertTrue(Account::where('name', 'Input VAT')->exists());
        $this->assertTrue(Account::where('name', 'Withholding Tax Payable - Expanded')->exists());

        // Every parent shares its child's type.
        Account::whereNotNull('parent_id')->with('parent')->get()
            ->each(fn ($a) => $this->assertSame($a->type, $a->parent->type, $a->code));

        $this->postJson('/api/v1/accounts/load-defaults')->assertStatus(422);
    }

    public function test_auditor_cannot_load_defaults(): void
    {
        $this->actingAsRole(Role::Auditor);
        $this->postJson('/api/v1/accounts/load-defaults')->assertForbidden();
    }

    public function test_parent_rules(): void
    {
        $this->actingAsRole(Role::Accountant);
        $group = Account::factory()->type(AccountType::Asset)->create(['code' => '1100']);
        $other = Account::factory()->type(AccountType::Liability)->create(['code' => '2100']);

        $child = $this->postJson('/api/v1/accounts', ['code' => '1110', 'name' => 'Cash', 'type' => 'asset', 'parent_id' => $group->id])
            ->assertCreated()->assertJsonPath('data.parent_id', $group->id)->json('data.id');

        // Different type.
        $this->postJson('/api/v1/accounts', ['code' => '2110', 'name' => 'AP', 'type' => 'liability', 'parent_id' => $group->id])
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        // Cycle: the group under its own child.
        $this->putJson("/api/v1/accounts/{$group->id}", ['parent_id' => $child])->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
        // Self.
        $this->putJson("/api/v1/accounts/{$group->id}", ['parent_id' => $group->id])->assertStatus(422);
        // Group can't change type or be deleted while it has children.
        $this->putJson("/api/v1/accounts/{$group->id}", ['type' => 'expense'])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->deleteJson("/api/v1/accounts/{$group->id}")->assertStatus(422);
        // Unknown parent.
        $this->postJson('/api/v1/accounts', ['code' => '1120', 'name' => 'X', 'type' => 'asset', 'parent_id' => 999])
            ->assertJsonValidationErrors(['parent_id']);
        $this->assertNotNull($other);
    }

    public function test_entries_cannot_post_to_group_accounts(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();
        Account::factory()->type(AccountType::Asset)->create(['code' => '1001', 'parent_id' => $acc['asset']->id]);

        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '10', 0], [$acc['revenue'], 0, '10']]))
            ->assertStatus(422)
            ->assertJsonPath('message', "Group accounts can't be used on entries: {$acc['asset']->code}.");
    }

    public function test_account_with_activity_cannot_become_a_group(): void
    {
        $this->actingAsRole(Role::Accountant);
        $acc = $this->basicAccounts();
        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$acc['asset'], '10', 0], [$acc['revenue'], 0, '10']]))->assertCreated();

        $this->postJson('/api/v1/accounts', ['code' => '1001', 'name' => 'Sub', 'type' => 'asset', 'parent_id' => $acc['asset']->id])
            ->assertStatus(422)->assertJsonValidationErrors(['parent_id']);
    }
}
