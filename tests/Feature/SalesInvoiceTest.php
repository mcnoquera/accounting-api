<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ControlType;
use App\Enums\Role;
use App\Enums\TaxRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\TaxCode;
use App\Services\Exports\Exporter;
use App\Services\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    private Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 09:00:00');
        $this->actingAsRole(Role::Accountant);
        Company::create(['name' => 'Bayanihan Trading Corp.', 'tin' => '123-456-789-000', 'address' => 'Makati City', 'vat_registered' => true, 'currency' => 'PHP', 'fiscal_year_start_month' => 1]);
        $make = fn (string $code, AccountType $type, array $extra = []) => Account::factory()->type($type)->create(['code' => $code] + $extra);
        $this->a = [
            'cash' => $make('1110', AccountType::Asset),
            'ar' => $make('1210', AccountType::Asset, ['control_type' => ControlType::Receivable]),
            'output_vat' => $make('2210', AccountType::Liability, ['tax_role' => TaxRole::OutputVat]),
            'sales' => $make('4100', AccountType::Revenue),
            'services' => $make('4200', AccountType::Revenue),
            'rent' => $make('6040', AccountType::Expense),
        ];
        $this->customer = Contact::factory()->create(['name' => 'Mabuhay Retail Inc.', 'tin' => '200-333-444-000', 'payment_terms_days' => 30, 'address' => 'Pasig City']);
    }

    private function code(string $c): int
    {
        return TaxCode::where('code', $c)->value('id');
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'contact_id' => $this->customer->id,
            'invoice_date' => '2026-11-10',
            'reference' => 'PO-7788',
            'lines' => [
                ['description' => 'Rice, 25kg sack', 'quantity' => '10', 'unit_price' => '1250.00', 'account_id' => $this->a['sales']->id, 'vat_code_id' => $this->code('VAT12')],
                ['description' => 'Delivery', 'quantity' => '1.5', 'unit_price' => '333.33', 'account_id' => $this->a['services']->id, 'vat_code_id' => $this->code('VAT12')],
                ['description' => 'Export packaging', 'quantity' => '1', 'unit_price' => '200', 'account_id' => $this->a['sales']->id, 'vat_code_id' => $this->code('VAT0')],
            ],
        ];
    }

    public function test_draft_computes_totals_and_due_date(): void
    {
        $inv = $this->postJson('/api/v1/sales-invoices', $this->payload())->assertCreated()->json('data');

        $this->assertSame(['draft', null, '2026-12-10'], [$inv['status'], $inv['number'], $inv['due_date']]);
        // 12,500.00 + 500.00 (1.5 × 333.33 = 499.995 → 500.00) + 200.00
        $this->assertSame([1250000, 50000, 20000], array_column($inv['lines'], 'amount'));
        $this->assertSame('1.5', $inv['lines'][1]['quantity']);
        // VAT 12% on 12,500 (sales/VAT12) and on 500 (services/VAT12): 1,500 + 60
        $this->assertSame([1320000, 156000, 1476000], [$inv['net_amount'], $inv['vat_amount'], $inv['total_amount']]);
        $this->assertSame(0, $inv['balance_due']);
    }

    public function test_issue_posts_the_entry_and_numbers_the_invoice(): void
    {
        $id = $this->postJson('/api/v1/sales-invoices', $this->payload())->json('data.id');
        $inv = $this->postJson("/api/v1/sales-invoices/{$id}/issue")->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.number', 'SI-000001')
            ->assertJsonPath('data.balance_due', 1476000)
            ->json('data');

        $entry = JournalEntry::with('lines')->find($inv['journal_entry_id']);
        $this->assertSame(['posted', 'sales_invoice', $id, '2026-11-10', 'SI-000001'], [$entry->status->value, $entry->source_type, $entry->source_id, $entry->entry_date->format('Y-m-d'), $entry->reference]);
        $by = $entry->lines->keyBy(fn ($l) => $l->account_id.'|'.$l->vat_code_id);
        $this->assertSame(1476000, $by[$this->a['ar']->id.'|']->debit);
        $this->assertSame($this->customer->id, $by[$this->a['ar']->id.'|']->contact_id);
        $this->assertSame(1250000, $by[$this->a['sales']->id.'|'.$this->code('VAT12')]->credit);
        $this->assertSame(20000, $by[$this->a['sales']->id.'|'.$this->code('VAT0')]->credit);
        $this->assertSame(156000, $by[$this->a['output_vat']->id.'|']->credit);

        // Second invoice gets the next number; issued invoices are read-only.
        $id2 = $this->postJson('/api/v1/sales-invoices', $this->payload())->json('data.id');
        $this->postJson("/api/v1/sales-invoices/{$id2}/issue")->assertJsonPath('data.number', 'SI-000002');
        $this->putJson("/api/v1/sales-invoices/{$id}", $this->payload())->assertStatus(422)->assertJsonPath('message', 'Only draft invoices can be edited.');
        $this->deleteJson("/api/v1/sales-invoices/{$id}")->assertStatus(422);

        // Shows up in VAT summary and receivables aging like any other entry.
        $this->getJson('/api/v1/reports/vat-summary?year=2026&quarter=4')->assertJsonPath('data.total.output_vat', 312000);
        $this->getJson('/api/v1/reports/aging?type=receivable')->assertJsonPath('data.totals.total', 2952000);
    }

    public function test_the_posted_entry_can_only_change_through_the_invoice(): void
    {
        $id = $this->postJson('/api/v1/sales-invoices', $this->payload())->json('data.id');
        $entryId = $this->postJson("/api/v1/sales-invoices/{$id}/issue")->json('data.journal_entry_id');

        $this->postJson("/api/v1/journal-entries/{$entryId}/void", ['reason' => 'Trying to void'])->assertStatus(422)
            ->assertJsonPath('message', 'This entry was posted by a sales invoice. Void the sales invoice instead.');
        $this->postJson("/api/v1/journal-entries/{$entryId}/reverse", ['reason' => 'Trying to reverse'])->assertStatus(422);
    }

    public function test_void_reverses_the_entry(): void
    {
        $id = $this->postJson('/api/v1/sales-invoices', $this->payload())->json('data.id');
        $entryId = $this->postJson("/api/v1/sales-invoices/{$id}/issue")->json('data.journal_entry_id');

        $this->postJson("/api/v1/sales-invoices/{$id}/void")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/sales-invoices/{$id}/void", ['reason' => 'Wrong customer'])->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.balance_due', 0);

        $reversal = JournalEntry::query()->where('reversal_of_id', $entryId)->sole();
        $this->assertSame(['2026-11-10', 'sales_invoice', 'Reversal of '.JournalEntry::find($entryId)->number.': Invoice SI-000001 voided: Wrong customer'],
            [$reversal->entry_date->format('Y-m-d'), $reversal->source_type, $reversal->description]);
        $this->getJson('/api/v1/reports/aging?type=receivable')->assertJsonPath('data.totals.total', 0);
        $this->postJson("/api/v1/sales-invoices/{$id}/void", ['reason' => 'Again please'])->assertStatus(422);
    }

    public function test_void_in_a_closed_period_reverses_on_the_first_open_date(): void
    {
        $id = $this->postJson('/api/v1/sales-invoices', $this->payload(['invoice_date' => '2026-10-20']))->json('data.id');
        $this->postJson("/api/v1/sales-invoices/{$id}/issue")->assertOk();
        $this->postJson('/api/v1/periods/2026-10/close')->assertOk();

        $this->postJson("/api/v1/sales-invoices/{$id}/void", ['reason' => 'Customer cancelled'])->assertOk();
        $this->assertSame('2026-11-01', JournalEntry::query()->whereNotNull('reversal_of_id')->sole()->entry_date->format('Y-m-d'));
    }

    public function test_issue_respects_the_period_lock_and_needs_control_accounts(): void
    {
        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$this->a['rent'], '1', 0], [$this->a['cash'], 0, '1']], date: '2026-09-01'))->assertCreated();
        $this->postJson('/api/v1/periods/2026-09/close')->assertOk();
        $this->postJson('/api/v1/periods/2026-10/close')->assertOk();

        $id = $this->postJson('/api/v1/sales-invoices', $this->payload(['invoice_date' => '2026-10-15']))->json('data.id');
        $this->postJson("/api/v1/sales-invoices/{$id}/issue")->assertStatus(422)
            ->assertJsonPath('message', 'The entry date is in a closed period (2026-10).');
        $this->assertSame('draft', $this->getJson("/api/v1/sales-invoices/{$id}")->json('data.status'));

        $this->a['ar']->update(['control_type' => null]);
        $id2 = $this->postJson('/api/v1/sales-invoices', $this->payload())->json('data.id');
        $this->postJson("/api/v1/sales-invoices/{$id2}/issue")->assertStatus(422)
            ->assertJsonPath('message', 'No active receivable control account. Mark your trade receivables account as a receivable control account in the chart of accounts.');
    }

    public function test_validation(): void
    {
        $supplier = Contact::factory()->supplier()->create();
        $this->postJson('/api/v1/sales-invoices', $this->payload(['contact_id' => $supplier->id]))->assertStatus(422)
            ->assertJsonPath('message', "{$supplier->name} isn't an active customer.");
        $bad = $this->payload();
        $bad['lines'][0]['account_id'] = $this->a['rent']->id;
        $this->postJson('/api/v1/sales-invoices', $bad)->assertStatus(422)->assertJsonPath('message', 'Invoice lines post to active revenue accounts.');
        $bad = $this->payload();
        $bad['lines'][0]['quantity'] = '1.2345';
        $this->postJson('/api/v1/sales-invoices', $bad)->assertStatus(422)->assertJsonValidationErrors('lines.0.quantity');
        $this->postJson('/api/v1/sales-invoices', $this->payload(['due_date' => '2026-11-01']))->assertStatus(422)->assertJsonValidationErrors('due_date');

        $this->actingAsRole(Role::Auditor);
        $this->postJson('/api/v1/sales-invoices', $this->payload())->assertForbidden();
        $this->getJson('/api/v1/sales-invoices')->assertOk();
    }

    public function test_list_filters_and_pdf(): void
    {
        $draft = $this->postJson('/api/v1/sales-invoices', $this->payload())->json('data.id');
        $issued = $this->postJson('/api/v1/sales-invoices', $this->payload(['invoice_date' => '2026-09-01']))->json('data.id');
        $this->postJson("/api/v1/sales-invoices/{$issued}/issue")->assertOk();

        $this->assertSame([$draft], array_column($this->getJson('/api/v1/sales-invoices?status=draft')->json('data'), 'id'));
        $this->assertSame([$issued], array_column($this->getJson('/api/v1/sales-invoices?status=overdue')->json('data'), 'id'));
        $this->assertSame([$issued], array_column($this->getJson('/api/v1/sales-invoices?q=SI-0000')->json('data'), 'id'));

        $res = $this->get("/api/v1/sales-invoices/{$issued}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $res->getContent());
        $this->assertStringContainsString('SI-000001.pdf', $res->headers->get('Content-Disposition'));
        $this->assertSame('printed', AuditLog::query()->latest('id')->value('action'));

        $html = view('exports.invoice', [
            'invoice' => app(SalesInvoiceService::class)->load(SalesInvoice::find($issued)),
            'company' => Company::current(),
            'money' => fn (int $c) => Exporter::pesos($c, true),
        ])->render();
        foreach (['SALES INVOICE', 'No. SI-000001', 'VAT Reg. TIN: 123-456-789-000', 'Mabuhay Retail Inc.', 'TIN: 200-333-444-000', 'Rice, 25kg sack',
            'VATable sales', '13,000.00', '1,560.00', 'Zero-rated sales', '200.00', 'TOTAL AMOUNT DUE', '14,760.00', '(zero-rated)'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }
}
