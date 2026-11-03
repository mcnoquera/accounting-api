<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ControlType;
use App\Enums\Role;
use App\Enums\TaxRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseBillTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 09:00:00');
        $this->actingAsRole(Role::Accountant);
        Company::create(['name' => 'Bayanihan Trading Corp.', 'tin' => '123-456-789-000', 'address' => 'Makati City', 'vat_registered' => true, 'currency' => 'PHP', 'fiscal_year_start_month' => 1]);
        $make = fn (string $code, AccountType $type, array $extra = []) => Account::factory()->type($type)->create(['code' => $code] + $extra);
        $this->a = [
            'cash' => $make('1110', AccountType::Asset),
            'input_vat' => $make('1420', AccountType::Asset, ['tax_role' => TaxRole::InputVat]),
            'equipment' => $make('1540', AccountType::Asset),
            'ap' => $make('2110', AccountType::Liability, ['control_type' => ControlType::Payable]),
            'ewt' => $make('2230', AccountType::Liability, ['tax_role' => TaxRole::EwtPayable]),
            'sales' => $make('4100', AccountType::Revenue),
            'rent' => $make('6040', AccountType::Expense),
            'repairs' => $make('6090', AccountType::Expense),
        ];
        $this->landlord = Contact::factory()->supplier()->create(['name' => 'Makati Property Holdings', 'tin' => '300-333-444-000', 'payment_terms_days' => 15]);
    }

    private function code(string $c): int
    {
        return TaxCode::where('code', $c)->value('id');
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'contact_id' => $this->landlord->id,
            'supplier_invoice_number' => 'MPH-2026-1101',
            'bill_date' => '2026-11-01',
            'lines' => [
                ['description' => 'November office rent', 'amount' => '45000.00', 'account_id' => $this->a['rent']->id, 'vat_code_id' => $this->code('VAT12'), 'ewt_code_id' => $this->code('WC100')],
                ['description' => 'Aircon repair', 'amount' => '2000.00', 'account_id' => $this->a['repairs']->id, 'vat_code_id' => $this->code('VAT12'), 'ewt_code_id' => $this->code('WC160')],
                ['description' => 'Filing cabinet', 'amount' => '8000', 'account_id' => $this->a['equipment']->id, 'vat_code_id' => $this->code('VAT12')],
            ],
        ];
    }

    public function test_draft_totals_with_vat_and_withholding(): void
    {
        $bill = $this->postJson('/api/v1/purchase-bills', $this->payload())->assertCreated()->json('data');
        // Net 55,000; VAT 12% = 6,600; EWT 5% of 45,000 + 2% of 2,000 = 2,250 + 40.
        $this->assertSame([5500000, 660000, 229000, 6160000, 5931000], [$bill['net_amount'], $bill['vat_amount'], $bill['ewt_amount'], $bill['total_amount'], $bill['amount_due']]);
        $this->assertSame(['draft', '2026-11-16'], [$bill['status'], $bill['due_date']]);
    }

    public function test_record_posts_the_entry_and_feeds_reports(): void
    {
        $id = $this->postJson('/api/v1/purchase-bills', $this->payload())->json('data.id');
        $bill = $this->postJson("/api/v1/purchase-bills/{$id}/record")->assertOk()
            ->assertJsonPath('data.number', 'PB-000001')
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.balance_due', 5931000)
            ->json('data');

        $entry = JournalEntry::with('lines')->find($bill['journal_entry_id']);
        $this->assertSame(['purchase_bill', $id, 'MPH-2026-1101'], [$entry->source_type, $entry->source_id, $entry->reference]);
        $sum = fn (string $acct, string $side) => (int) $entry->lines->where('account_id', $this->a[$acct]->id)->sum($side);
        $this->assertSame([4500000, 200000, 800000, 660000], [$sum('rent', 'debit'), $sum('repairs', 'debit'), $sum('equipment', 'debit'), $sum('input_vat', 'debit')]);
        $this->assertSame([229000, 5931000], [$sum('ewt', 'credit'), $sum('ap', 'credit')]);
        $this->assertSame($this->landlord->id, $entry->lines->firstWhere('account_id', $this->a['ap']->id)->contact_id);

        // Payables aging, VAT summary and the withholding payee list pick it up.
        $this->getJson('/api/v1/reports/aging?type=payable')->assertJsonPath('data.totals.total', 5931000);
        $this->getJson('/api/v1/reports/vat-summary?year=2026&quarter=4')->assertJsonPath('data.total.input_vat', 660000);
        $payees = $this->getJson('/api/v1/reports/withholding?year=2026&quarter=4')->json('data.withheld.payees');
        $this->assertSame([['Makati Property Holdings', 'WC100', 225000], ['Makati Property Holdings', 'WC160', 4000]],
            array_map(fn ($p) => [$p['name'], $p['atc'], $p['tax']], $payees));
    }

    public function test_supplier_invoice_numbers_are_unique_per_supplier(): void
    {
        $id = $this->postJson('/api/v1/purchase-bills', $this->payload())->json('data.id');
        $this->postJson("/api/v1/purchase-bills/{$id}/record")->assertOk();

        $this->postJson('/api/v1/purchase-bills', $this->payload())->assertStatus(422)
            ->assertJsonPath('message', 'This supplier invoice number is already on PB-000001.');
        $other = Contact::factory()->supplier()->create();
        $this->postJson('/api/v1/purchase-bills', $this->payload(['contact_id' => $other->id]))->assertCreated();

        // After voiding, the number can be used again (e.g. re-entering a corrected bill).
        $this->postJson("/api/v1/purchase-bills/{$id}/void", ['reason' => 'Wrong amounts'])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->postJson('/api/v1/purchase-bills', $this->payload())->assertCreated();
        $this->getJson('/api/v1/reports/aging?type=payable')->assertJsonPath('data.totals.total', 0);
    }

    public function test_validation_and_document_lock(): void
    {
        $bad = $this->payload();
        $bad['lines'][0]['account_id'] = $this->a['sales']->id;
        $this->postJson('/api/v1/purchase-bills', $bad)->assertStatus(422)
            ->assertJsonPath('message', 'Bill lines post to active expense or asset accounts (not tax or control accounts).');
        $bad = $this->payload();
        $bad['lines'][0]['account_id'] = $this->a['input_vat']->id;
        $this->postJson('/api/v1/purchase-bills', $bad)->assertStatus(422);
        $customer = Contact::factory()->create();
        $this->postJson('/api/v1/purchase-bills', $this->payload(['contact_id' => $customer->id]))->assertStatus(422)
            ->assertJsonPath('message', "{$customer->name} isn't an active supplier.");
        $bad = $this->payload();
        $bad['lines'][0]['ewt_code_id'] = $this->code('VAT12');
        $this->postJson('/api/v1/purchase-bills', $bad)->assertStatus(422)->assertJsonValidationErrors('lines.0.ewt_code_id');

        $id = $this->postJson('/api/v1/purchase-bills', $this->payload())->json('data.id');
        $entryId = $this->postJson("/api/v1/purchase-bills/{$id}/record")->json('data.journal_entry_id');
        $this->postJson("/api/v1/journal-entries/{$entryId}/void", ['reason' => 'Trying to void'])->assertStatus(422)
            ->assertJsonPath('message', 'This entry was posted by a purchase bill. Void the purchase bill instead.');
        $this->putJson("/api/v1/purchase-bills/{$id}", $this->payload())->assertStatus(422);

        $this->actingAsRole(Role::Auditor);
        $this->postJson('/api/v1/purchase-bills', $this->payload(['supplier_invoice_number' => 'X-1']))->assertForbidden();
        $this->assertCount(1, $this->getJson('/api/v1/purchase-bills?status=open')->json('data'));
    }
}
