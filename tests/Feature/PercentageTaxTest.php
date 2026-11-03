<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PercentageTaxTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole(Role::Accountant);
        $this->a = [
            'cash' => Account::factory()->type(AccountType::Asset)->create(['code' => '1110']),
            'sales' => Account::factory()->type(AccountType::Revenue)->create(['code' => '4010']),
            'interest' => Account::factory()->type(AccountType::Revenue)->create(['code' => '4020']),
        ];
        Company::create(['name' => 'Sari-sari Store', 'tin' => '123-456-789-000', 'address' => 'Quezon City', 'vat_registered' => false, 'currency' => 'PHP', 'fiscal_year_start_month' => 1]);
    }

    private function sale(string $date, string $amount, string $account = 'sales', ?string $vat = null, bool $return = false): int
    {
        $vatId = $vat ? TaxCode::where('code', $vat)->value('id') : null;
        $lines = $return
            ? [['account_id' => $this->a[$account]->id, 'debit' => $amount, 'vat_code_id' => $vatId], ['account_id' => $this->a['cash']->id, 'credit' => $amount]]
            : [['account_id' => $this->a['cash']->id, 'debit' => $amount], ['account_id' => $this->a[$account]->id, 'credit' => $amount, 'vat_code_id' => $vatId]];
        $id = $this->postJson('/api/v1/journal-entries', ['entry_date' => $date, 'description' => 'Sale', 'lines' => $lines])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();

        return $id;
    }

    public function test_quarter_summary_at_the_default_three_percent(): void
    {
        $this->sale('2026-04-10', '10000.00');
        $this->sale('2026-05-03', '2500.55');
        $this->sale('2026-05-20', '1000.00', 'sales', 'VATEX'); // exempt
        $this->sale('2026-06-15', '300.00', 'sales', null, true); // sales return
        $this->sale('2026-06-30', '0.10', 'interest');
        $this->sale('2026-07-01', '9999.00'); // next quarter

        $p = $this->getJson('/api/v1/reports/percentage-tax?year=2026&quarter=2')->assertOk()->json('data');
        $this->assertSame([false, 300, '3.00'], [$p['vat_registered'], $p['rate_bp'], $p['rate']]);
        $this->assertSame([1000000, 350055, -29990], array_column($p['months'], 'gross'));
        $this->assertSame([0, 100000, 0], array_column($p['months'], 'exempt'));
        $this->assertSame([30000, 7502, -900], array_column($p['months'], 'tax'));
        // 1,220,065 cents taxable × 3% = 36,601.95 → 36,602 (half up)
        $this->assertSame(['gross' => 1320065, 'exempt' => 100000, 'taxable' => 1220065, 'tax' => 36602], $p['total']);
    }

    public function test_rate_is_configurable_on_the_company(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->putJson('/api/v1/company', [
            'name' => 'Sari-sari Store', 'tin' => '123-456-789-000', 'address' => 'Quezon City', 'vat_registered' => false,
            'fiscal_year_start_month' => 1, 'percentage_tax_rate' => '1',
        ])->assertOk()->assertJsonPath('data.percentage_tax_rate_bp', 100);

        $this->sale('2026-01-10', '10000.00');
        $this->getJson('/api/v1/reports/percentage-tax?year=2026&quarter=1')
            ->assertJsonPath('data.rate', '1.00')
            ->assertJsonPath('data.total.tax', 10000);

        $this->putJson('/api/v1/company', [
            'name' => 'Sari-sari Store', 'tin' => '123-456-789-000', 'address' => 'Quezon City', 'vat_registered' => false,
            'fiscal_year_start_month' => 1, 'percentage_tax_rate' => '150',
        ])->assertStatus(422)->assertJsonValidationErrors('percentage_tax_rate');
    }

    public function test_closing_entries_and_drafts_are_excluded_and_exports_work(): void
    {
        $this->sale('2026-02-01', '1000.00');
        $this->postJson('/api/v1/journal-entries', ['entry_date' => '2026-02-02', 'description' => 'Draft', 'lines' => [
            ['account_id' => $this->a['cash']->id, 'debit' => '500'], ['account_id' => $this->a['sales']->id, 'credit' => '500'],
        ]])->assertCreated();

        $this->getJson('/api/v1/reports/percentage-tax?year=2026&quarter=1')->assertJsonPath('data.total.gross', 100000);

        $csv = $this->get('/api/v1/reports/percentage-tax/export?format=csv&year=2026&quarter=1')->assertOk()->getContent();
        $this->assertStringContainsString('"Percentage tax due (3.00%)",0.00,30.00,0.00,30.00', $csv);
        $this->assertStringStartsWith('%PDF-', $this->get('/api/v1/reports/percentage-tax/export?format=pdf&year=2026&quarter=1')->getContent());
    }
}
