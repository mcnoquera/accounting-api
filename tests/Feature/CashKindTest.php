<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_ph_chart_tags_cash_bank_and_gcash(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->postJson('/api/v1/accounts/load-defaults')->assertSuccessful();
        $kinds = collect($this->getJson('/api/v1/accounts')->json('data'))->whereNotNull('cash_kind')->mapWithKeys(fn ($a) => [$a['code'] => [$a['cash_kind'], $a['provider']]])->all();
        $this->assertEquals([
            '1110' => ['cash', null], '1120' => ['cash', null], '1130' => ['bank', null], '1140' => ['bank', null], '1151' => ['ewallet', 'GCash'],
        ], $kinds);
        $gcash = Account::where('code', '1151')->first();
        $this->assertSame(['1150', true], [$gcash->parent->code, $gcash->is_cash]);
    }

    public function test_kind_keeps_is_cash_in_sync_and_only_on_assets(): void
    {
        $this->actingAsRole(Role::Admin);
        $bpi = $this->postJson('/api/v1/accounts', ['code' => '1135', 'name' => 'BPI Current', 'type' => 'asset', 'cash_kind' => 'bank', 'provider' => 'BPI', 'account_number_last4' => '4321'])
            ->assertCreated()->assertJsonPath('data.is_cash', true)->assertJsonPath('data.cash_kind', 'bank')->json('data.id');
        $this->putJson("/api/v1/accounts/{$bpi}", ['cash_kind' => null])->assertOk()->assertJsonPath('data.is_cash', false);
        $this->putJson("/api/v1/accounts/{$bpi}", ['is_cash' => true])->assertOk()->assertJsonPath('data.cash_kind', 'cash');

        $this->postJson('/api/v1/accounts', ['code' => '2999', 'name' => 'Odd', 'type' => 'liability', 'cash_kind' => 'ewallet'])->assertStatus(422)
            ->assertJsonPath('errors.cash_kind.0', 'Only asset accounts can be cash, bank or e-wallet accounts.');
        $this->postJson('/api/v1/accounts', ['code' => '1136', 'name' => 'X', 'type' => 'asset', 'cash_kind' => 'bank', 'account_number_last4' => '12'])
            ->assertStatus(422)->assertJsonValidationErrors('account_number_last4');
    }

    public function test_transfer_between_gcash_and_bank_with_fee(): void
    {
        $this->actingAsRole(Role::Accountant);
        $gcash = Account::factory()->type(AccountType::Asset)->create(['code' => '1151', 'name' => 'GCash', 'is_cash' => true, 'cash_kind' => 'ewallet']);
        $bank = Account::factory()->type(AccountType::Asset)->create(['code' => '1130', 'name' => 'BPI Current', 'is_cash' => true, 'cash_kind' => 'bank']);
        $fees = Account::factory()->type(AccountType::Expense)->create(['code' => '6160', 'name' => 'Bank Charges']);
        $sales = Account::factory()->type(AccountType::Revenue)->create(['code' => '4100']);

        $entry = $this->postJson('/api/v1/transfers', [
            'date' => '2026-11-03', 'from_account_id' => $gcash->id, 'to_account_id' => $bank->id, 'amount' => '10000.00', 'fee' => '15.00', 'fee_account_id' => $fees->id, 'reference' => 'GC-REF-998877',
        ])->assertCreated()->assertJsonPath('data.status', 'posted')->json('data');
        $lines = JournalEntry::with('lines')->find($entry['id'])->lines->keyBy('account_id');
        $this->assertSame([1000000, 1500, 1001500], [$lines[$bank->id]->debit, $lines[$fees->id]->debit, $lines[$gcash->id]->credit]);

        $this->postJson('/api/v1/transfers', ['date' => '2026-11-03', 'from_account_id' => $gcash->id, 'to_account_id' => $gcash->id, 'amount' => '1'])->assertStatus(422)
            ->assertJsonPath('message', 'Choose two different accounts.');
        $this->postJson('/api/v1/transfers', ['date' => '2026-11-03', 'from_account_id' => $sales->id, 'to_account_id' => $bank->id, 'amount' => '1'])->assertStatus(422);
        $this->postJson('/api/v1/transfers', ['date' => '2026-11-03', 'from_account_id' => $gcash->id, 'to_account_id' => $bank->id, 'amount' => '1', 'fee' => '1', 'fee_account_id' => $sales->id])->assertStatus(422)
            ->assertJsonPath('message', 'Choose the expense account for the fee (e.g. Bank Charges).');

        // Dashboard groups cash balances by kind.
        $kinds = collect($this->getJson('/api/v1/dashboard')->json('data.cash_by_kind'))->keyBy('kind');
        $this->assertSame([1000000, -1001500], [$kinds['bank']['total'], $kinds['ewallet']['total']]);
    }
}
