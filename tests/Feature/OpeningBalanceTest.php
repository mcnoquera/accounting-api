<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ControlType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpeningBalanceTest extends TestCase
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
        $make = fn (string $code, AccountType $type, array $extra = []) => Account::factory()->type($type)->create(['code' => $code] + $extra);
        $this->a = [
            'bank' => $make('1130', AccountType::Asset, ['is_cash' => true]),
            'ar' => $make('1210', AccountType::Asset, ['control_type' => ControlType::Receivable]),
            'equipment' => $make('1540', AccountType::Asset),
            'ap' => $make('2110', AccountType::Liability, ['control_type' => ControlType::Payable]),
            'loan' => $make('2510', AccountType::Liability),
            'capital' => $make('3100', AccountType::Equity),
            'sales' => $make('4100', AccountType::Revenue),
        ];
        $this->customer = Contact::factory()->create(['name' => 'Old Customer', 'payment_terms_days' => 30]);
        $this->supplier = Contact::factory()->supplier()->create(['name' => 'Old Supplier', 'payment_terms_days' => 30]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'cutover_date' => '2026-07-01',
            'lines' => [
                ['account_id' => $this->a['bank']->id, 'debit' => '500000.00'],
                ['account_id' => $this->a['equipment']->id, 'debit' => '120000.00'],
                ['account_id' => $this->a['loan']->id, 'credit' => '200000.00'],
                ['account_id' => $this->a['capital']->id, 'credit' => '300000.00'],
            ],
            'receivables' => [
                ['contact_id' => $this->customer->id, 'number' => 'INV-0457', 'date' => '2026-05-20', 'amount' => '56000.00'],
                ['contact_id' => $this->customer->id, 'number' => 'INV-0471', 'date' => '2026-06-25', 'due_date' => '2026-08-31', 'amount' => '14000.00'],
            ],
            'payables' => [
                ['contact_id' => $this->supplier->id, 'number' => 'S-9921', 'date' => '2026-06-10', 'amount' => '30000.00'],
            ],
        ];
    }

    public function test_opening_entry_documents_equity_and_period_lock(): void
    {
        $ob = $this->putJson('/api/v1/opening-balances', $this->payload())->assertOk()->json('data');
        $this->assertSame(['2026-07-01', '2026-06-30', true], [$ob['cutover_date'], $ob['as_of'], $ob['replaceable']]);

        $entry = JournalEntry::with('lines')->find($ob['journal_entry']['id']);
        $this->assertSame(['opening_balance', '2026-06-30', 'posted'], [$entry->source_type, $entry->entry_date->format('Y-m-d'), $entry->status->value]);
        $this->assertSame((int) $entry->lines->sum('debit'), (int) $entry->lines->sum('credit'));
        // Debits 500k + 120k + 70k AR = 690k; credits 200k + 300k + 30k AP = 530k → 160k to Opening Balance Equity.
        $obe = Account::where('code', '3900')->sole();
        $this->assertSame(['Opening Balance Equity', 16000000], [$obe->name, (int) $entry->lines->firstWhere('account_id', $obe->id)->credit]);

        // Opening documents: aging works from day one with their own due dates.
        $this->assertSame(['OI-000001', 'OI-000002'], array_column($ob['receivables'], 'number'));
        $this->assertSame('2026-06-19', $ob['receivables'][0]['due_date']);
        $aging = $this->getJson('/api/v1/reports/aging?type=receivable&as_of=2026-07-15')->json('data');
        $this->assertSame([7000000, 0], [$aging['totals']['total'], $aging['unassigned']]);
        $this->assertSame(5600000, $aging['rows'][0]['days_1_30']);
        $this->getJson('/api/v1/reports/aging?type=payable&as_of=2026-07-15')->assertJsonPath('data.totals.total', 3000000)->assertJsonPath('data.unassigned', 0);

        // Everything before cut-over is locked; trial balance balances.
        $this->getJson('/api/v1/periods')->assertJsonPath('meta.locked_through', '2026-06-30');
        $this->getJson('/api/v1/reports/trial-balance')->assertJsonPath('data.balanced', true);
        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$this->a['bank'], '1', 0], [$this->a['sales'], 0, '1']], date: '2026-06-15'))->assertStatus(422);

        // A receipt can settle an opening invoice; then opening balances can't be replaced.
        $inv = $ob['receivables'][0]['id'];
        $this->postJson('/api/v1/payments', ['direction' => 'receipt', 'contact_id' => $this->customer->id, 'payment_date' => '2026-07-05',
            'cash_account_id' => $this->a['bank']->id, 'amount' => '56000.00', 'allocations' => [['document_id' => $inv, 'amount' => '56000.00']]])->assertCreated();
        $this->getJson("/api/v1/sales-invoices/{$inv}")->assertJsonPath('data.status', 'paid');
        $this->putJson('/api/v1/opening-balances', $this->payload())->assertStatus(422)
            ->assertJsonPath('message', 'An opening invoice or bill already has payments, so the opening balances can no longer be replaced.');
        $this->getJson('/api/v1/opening-balances')->assertJsonPath('data.replaceable', false);
    }

    public function test_replace_with_the_same_cutover(): void
    {
        $this->putJson('/api/v1/opening-balances', $this->payload())->assertOk();
        $smaller = $this->payload(['receivables' => [], 'payables' => []]);
        $ob = $this->putJson('/api/v1/opening-balances', $smaller)->assertOk()->json('data');

        $this->assertSame([[], []], [$ob['receivables'], $ob['payables']]);
        $this->assertSame(1, JournalEntry::where('source_type', 'opening_balance')->count());
        $this->getJson('/api/v1/reports/aging?type=receivable')->assertJsonPath('data.totals.total', 0);
        $this->getJson('/api/v1/reports/trial-balance')->assertJsonPath('data.balanced', true);

        $this->putJson('/api/v1/opening-balances', $this->payload(['cutover_date' => '2026-08-01']))->assertStatus(422)
            ->assertJsonPath('message', 'Opening balances were entered for cut-over 2026-07-01. Replace them with the same cut-over date.');
    }

    public function test_validation(): void
    {
        $this->putJson('/api/v1/opening-balances', $this->payload(['cutover_date' => '2026-07-15']))->assertStatus(422)
            ->assertJsonValidationErrors('cutover_date');
        $bad = $this->payload();
        $bad['lines'][] = ['account_id' => $this->a['ar']->id, 'debit' => '1.00'];
        $this->putJson('/api/v1/opening-balances', $bad)->assertStatus(422)
            ->assertJsonPath('message', '1210 '.$this->a['ar']->name.' is a control account: enter its balance as opening invoices per customer.');
        $bad = $this->payload();
        $bad['receivables'][0]['contact_id'] = $this->supplier->id;
        $this->putJson('/api/v1/opening-balances', $bad)->assertStatus(422)->assertJsonPath('message', 'Opening receivables need a customer on every row.');
        $bad = $this->payload();
        $bad['payables'][0]['date'] = '2026-07-02';
        $this->putJson('/api/v1/opening-balances', $bad)->assertStatus(422);

        // Not allowed once the books already have entries before cut-over.
        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$this->a['bank'], '1', 0], [$this->a['sales'], 0, '1']], date: '2026-03-01'))->assertCreated();
        $this->putJson('/api/v1/opening-balances', $this->payload())->assertStatus(422)
            ->assertJsonPath('message', 'There are already entries dated before the cut-over date. Opening balances replace everything before cut-over, so start from an empty period.');

        $this->actingAsRole(Role::Auditor);
        $this->putJson('/api/v1/opening-balances', $this->payload())->assertForbidden();
    }
}
