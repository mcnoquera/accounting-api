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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    private Contact $customer;

    private Contact $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 09:00:00');
        $this->actingAsRole(Role::Accountant);
        Company::create(['name' => 'Co', 'tin' => '123-456-789-000', 'address' => 'Makati', 'vat_registered' => true, 'currency' => 'PHP', 'fiscal_year_start_month' => 1]);
        $make = fn (string $code, AccountType $type, array $extra = []) => Account::factory()->type($type)->create(['code' => $code] + $extra);
        $this->a = [
            'cash' => $make('1110', AccountType::Asset, ['is_cash' => true]),
            'bank' => $make('1130', AccountType::Asset, ['is_cash' => true]),
            'ar' => $make('1210', AccountType::Asset, ['control_type' => ControlType::Receivable]),
            'input_vat' => $make('1420', AccountType::Asset, ['tax_role' => TaxRole::InputVat]),
            'cwt' => $make('1430', AccountType::Asset, ['tax_role' => TaxRole::CwtReceivable]),
            'ap' => $make('2110', AccountType::Liability, ['control_type' => ControlType::Payable]),
            'output_vat' => $make('2210', AccountType::Liability, ['tax_role' => TaxRole::OutputVat]),
            'ewt' => $make('2230', AccountType::Liability, ['tax_role' => TaxRole::EwtPayable]),
            'services' => $make('4200', AccountType::Revenue),
            'rent' => $make('6040', AccountType::Expense),
        ];
        $this->customer = Contact::factory()->create(['name' => 'Client Corp', 'tin' => '200-111-222-000', 'payment_terms_days' => 30]);
        $this->supplier = Contact::factory()->supplier()->create(['name' => 'Landlord Inc', 'payment_terms_days' => 0]);
    }

    private function code(string $c): int
    {
        return TaxCode::where('code', $c)->value('id');
    }

    /** Issued invoice for net $amount + 12% VAT. */
    private function invoice(string $date, string $net): array
    {
        $id = $this->postJson('/api/v1/sales-invoices', [
            'contact_id' => $this->customer->id, 'invoice_date' => $date,
            'lines' => [['description' => 'Services', 'quantity' => '1', 'unit_price' => $net, 'account_id' => $this->a['services']->id, 'vat_code_id' => $this->code('VAT12')]],
        ])->json('data.id');

        return $this->postJson("/api/v1/sales-invoices/{$id}/issue")->assertOk()->json('data');
    }

    private function receipt(array $overrides): TestResponse
    {
        return $this->postJson('/api/v1/payments', $overrides + [
            'direction' => 'receipt', 'contact_id' => $this->customer->id, 'payment_date' => '2026-11-14', 'cash_account_id' => $this->a['bank']->id,
        ]);
    }

    public function test_receipt_with_2307_withholding_settles_an_invoice(): void
    {
        $inv = $this->invoice('2026-10-01', '10000.00'); // total 11,200

        // Customer withheld 2% (WC160) of the 10,000 net and paid 11,000.
        $p = $this->receipt([
            'amount' => '11000.00', 'ewt_code_id' => $this->code('WC160'), 'withholding_base' => '10000.00', 'withholding_amount' => '200.00',
            'allocations' => [['document_id' => $inv['id'], 'amount' => '11200.00']],
        ])->assertCreated()
            ->assertJsonPath('data.number', 'RC-000001')
            ->assertJsonPath('data.applied_amount', 1120000)
            ->assertJsonPath('data.unapplied_amount', 0)
            ->json('data');

        $entry = JournalEntry::with('lines')->find($p['journal_entry_id']);
        $sum = fn (string $acct, string $side) => (int) $entry->lines->where('account_id', $this->a[$acct]->id)->sum($side);
        $this->assertSame([1100000, 20000, 1120000], [$sum('bank', 'debit'), $sum('cwt', 'debit'), $sum('ar', 'credit')]);
        $this->assertSame('payment', $entry->source_type);

        $this->getJson("/api/v1/sales-invoices/{$inv['id']}")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.amount_paid', 1120000)
            ->assertJsonPath('data.balance_due', 0)
            ->assertJsonPath('data.payments.0.number', 'RC-000001');

        $this->getJson('/api/v1/reports/aging?type=receivable')->assertJsonPath('data.totals.total', 0)->assertJsonPath('data.unassigned', 0);
        $received = $this->getJson('/api/v1/reports/withholding?year=2026&quarter=4')->json('data.received');
        $this->assertSame([['Client Corp', 'WC160', 1000000, 20000]], array_map(fn ($r) => [$r['name'], $r['atc'], $r['base'], $r['tax']], $received['by_customer']));
        $this->assertSame(20000, $received['booked']);
    }

    public function test_partial_payment_and_exact_aging(): void
    {
        $old = $this->invoice('2026-08-01', '1000.00');  // 1,120, due Aug 31 → 76 days overdue on Nov 15
        $new = $this->invoice('2026-11-01', '2000.00');  // 2,240, due Dec 1 → current

        // Pays part of the NEW invoice only (oldest-first would have guessed wrong).
        $this->receipt(['amount' => '1000.00', 'allocations' => [['document_id' => $new['id'], 'amount' => '1000.00']]])->assertCreated();

        $this->getJson("/api/v1/sales-invoices/{$new['id']}")->assertJsonPath('data.status', 'partially_paid')->assertJsonPath('data.balance_due', 124000);
        $row = $this->getJson('/api/v1/reports/aging?type=receivable')->json('data.rows.0');
        $this->assertSame([124000, 112000, 236000], [$row['current'], $row['days_61_90'], $row['total']]);
        $this->assertSame(0, $this->getJson('/api/v1/reports/aging?type=receivable')->json('data.unassigned'));
    }

    public function test_over_receipt_stays_unapplied_and_can_be_allocated_later(): void
    {
        $inv1 = $this->invoice('2026-11-01', '1000.00'); // 1,120
        $id = $this->receipt(['amount' => '2000.00', 'allocations' => [['document_id' => $inv1['id'], 'amount' => '1120.00']]])
            ->assertJsonPath('data.unapplied_amount', 88000)->json('data.id');

        $a = $this->getJson('/api/v1/reports/aging?type=receivable')->json('data');
        $this->assertSame([-88000, -88000, 0], [$a['rows'][0]['current'], $a['totals']['total'], $a['unassigned']]);

        $inv2 = $this->invoice('2026-11-10', '500.00'); // 560
        $this->postJson("/api/v1/payments/{$id}/allocate", ['allocations' => [['document_id' => $inv2['id'], 'amount' => '600.00']]])->assertStatus(422)
            ->assertJsonPath('message', $inv2['number'].' has only 560.00 left to pay.');
        $this->postJson("/api/v1/payments/{$id}/allocate", ['allocations' => [['document_id' => $inv2['id'], 'amount' => '560.00']]])->assertOk()
            ->assertJsonPath('data.unapplied_amount', 32000);
        $this->getJson("/api/v1/sales-invoices/{$inv2['id']}")->assertJsonPath('data.status', 'paid');
    }

    public function test_allocation_rules(): void
    {
        $inv = $this->invoice('2026-11-01', '1000.00');
        $this->receipt(['amount' => '500.00', 'allocations' => [['document_id' => $inv['id'], 'amount' => '600.00']]])->assertStatus(422)
            ->assertJsonPath('message', 'Allocations add up to more than the payment (500.00).');

        $other = Contact::factory()->create(['name' => 'Other Co']);
        $this->receipt(['contact_id' => $other->id, 'amount' => '100.00', 'allocations' => [['document_id' => $inv['id'], 'amount' => '100.00']]])->assertStatus(422)
            ->assertJsonPath('message', $inv['number']." isn't an open invoice of Other Co.");

        $this->receipt(['amount' => '100.00', 'cash_account_id' => $this->a['rent']->id])->assertStatus(422)
            ->assertJsonPath('message', 'Choose an active cash or bank account.');
        $this->receipt(['amount' => '100.00', 'contact_id' => $this->supplier->id])->assertStatus(422)
            ->assertJsonPath('message', "Landlord Inc isn't an active customer.");
        $this->receipt(['amount' => '1000.00', 'ewt_code_id' => $this->code('WC160'), 'withholding_base' => '10000.00', 'withholding_amount' => '250.00'])
            ->assertStatus(422)->assertJsonPath('message', 'Withholding should be 2.00% of the income payment (200.00).');
        // Rejected receipts leave nothing behind.
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'payment')->count());
    }

    public function test_supplier_payment_settles_a_bill_and_void_restores_it(): void
    {
        $billId = $this->postJson('/api/v1/purchase-bills', [
            'contact_id' => $this->supplier->id, 'supplier_invoice_number' => 'R-11', 'bill_date' => '2026-11-01',
            'lines' => [['description' => 'Rent', 'amount' => '45000', 'account_id' => $this->a['rent']->id, 'vat_code_id' => $this->code('VAT12'), 'ewt_code_id' => $this->code('WC100')]],
        ])->json('data.id');
        $bill = $this->postJson("/api/v1/purchase-bills/{$billId}/record")->json('data'); // amount due 48,150

        $p = $this->postJson('/api/v1/payments', [
            'direction' => 'payment', 'contact_id' => $this->supplier->id, 'payment_date' => '2026-11-05', 'cash_account_id' => $this->a['bank']->id,
            'amount' => '48150.00', 'reference' => 'CHK-2001', 'allocations' => [['document_id' => $billId, 'amount' => '48150.00']],
        ])->assertCreated()->assertJsonPath('data.number', 'PY-000001')->json('data');

        $entry = JournalEntry::with('lines')->find($p['journal_entry_id']);
        $this->assertSame([4815000, 4815000], [(int) $entry->lines->firstWhere('account_id', $this->a['ap']->id)->debit, (int) $entry->lines->firstWhere('account_id', $this->a['bank']->id)->credit]);
        $this->getJson("/api/v1/purchase-bills/{$billId}")->assertJsonPath('data.status', 'paid');
        $this->getJson('/api/v1/reports/aging?type=payable')->assertJsonPath('data.totals.total', 0);

        // A paid bill can't be voided; voiding the payment reopens it.
        $this->postJson("/api/v1/purchase-bills/{$billId}/void", ['reason' => 'Mistake here'])->assertStatus(422);
        $this->postJson("/api/v1/payments/{$p['id']}/void", ['reason' => 'Cheque bounced'])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->getJson("/api/v1/purchase-bills/{$billId}")->assertJsonPath('data.status', 'issued')->assertJsonPath('data.balance_due', $bill['amount_due']);
        $this->assertSame(1, JournalEntry::query()->where('reversal_of_id', $p['journal_entry_id'])->count());
        $this->getJson('/api/v1/reports/aging?type=payable')->assertJsonPath('data.totals.total', 4815000)->assertJsonPath('data.unassigned', 0);
        $this->postJson("/api/v1/journal-entries/{$p['journal_entry_id']}/reverse", ['reason' => 'manual try'])->assertStatus(422);
    }

    public function test_cash_flag_only_on_assets_and_ph_chart(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->postJson('/api/v1/accounts', ['code' => '2999', 'name' => 'Odd', 'type' => 'liability', 'is_cash' => true])->assertStatus(422)
            ->assertJsonPath('errors.is_cash.0', 'Only asset accounts can be cash or bank accounts.');
        $this->postJson('/api/v1/accounts', ['code' => '1150', 'name' => 'GCash Wallet', 'type' => 'asset', 'is_cash' => true])->assertCreated()->assertJsonPath('data.is_cash', true);

        Account::query()->delete();
        $this->postJson('/api/v1/accounts/load-defaults')->assertSuccessful();
        $cash = collect($this->getJson('/api/v1/accounts')->json('data'))->where('is_cash', true)->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['1110', '1120', '1130', '1140', '1151'], $cash);
    }
}
