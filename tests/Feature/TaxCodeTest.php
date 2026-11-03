<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Enums\TaxRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxCodeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    private function chart(): void
    {
        $make = fn (string $code, AccountType $type, ?TaxRole $role = null) => Account::factory()->type($type)->create(['code' => $code, 'tax_role' => $role]);
        $this->a = [
            'cash' => $make('1110', AccountType::Asset),
            'ar' => $make('1210', AccountType::Asset),
            'input_vat' => $make('1420', AccountType::Asset, TaxRole::InputVat),
            'cwt' => $make('1430', AccountType::Asset, TaxRole::CwtReceivable),
            'ap' => $make('2110', AccountType::Liability),
            'output_vat' => $make('2210', AccountType::Liability, TaxRole::OutputVat),
            'ewt' => $make('2230', AccountType::Liability, TaxRole::EwtPayable),
            'sales' => $make('4010', AccountType::Revenue),
            'rent' => $make('6010', AccountType::Expense),
        ];
    }

    private function code(string $code): int
    {
        return TaxCode::where('code', $code)->value('id');
    }

    /** @param list<array{0:string, 1:string|int, 2:string|int, 3?:?string, 4?:string}> $lines [account key, debit, credit, VAT code, EWT code] */
    private function entry(array $lines, string $date = '2026-10-15'): array
    {
        return [
            'entry_date' => $date,
            'description' => 'Tax test',
            'lines' => array_map(fn ($l) => [
                'account_id' => $this->a[$l[0]]->id, 'debit' => (string) $l[1], 'credit' => (string) $l[2],
                'vat_code_id' => isset($l[3]) ? $this->code($l[3]) : null,
                'ewt_code_id' => isset($l[4]) ? $this->code($l[4]) : null,
            ], $lines),
        ];
    }

    public function test_seeded_codes_are_listed_with_rates(): void
    {
        $this->actingAsRole(Role::Auditor);
        $codes = collect($this->getJson('/api/v1/tax-codes')->assertOk()->json('data'))->keyBy('code');

        $this->assertSame(['12.00', 'vat', 'vatable'], [$codes['VAT12']['rate'], $codes['VAT12']['kind'], $codes['VAT12']['vat_category']]);
        $this->assertSame(['1.00', 'ewt', null], [$codes['WC158']['rate'], $codes['WC158']['kind'], $codes['WC158']['vat_category']]);
        $this->assertSame(['zero_rated', 'exempt'], [$codes['VAT0']['vat_category'], $codes['VATEX']['vat_category']]);
        $this->assertCount(3, $this->getJson('/api/v1/tax-codes?kind=vat&active=1')->json('data'));
    }

    public function test_bir_atc_schedule_is_seeded_with_common_codes_active(): void
    {
        $this->actingAsRole(Role::Accountant);
        $all = collect($this->getJson('/api/v1/tax-codes?kind=ewt')->json('data'))->keyBy('code');

        // Spot checks against the BIR 1601-EQ (Jan 2019) schedule.
        $expect = [
            'WI010' => ['5.00', true], 'WI011' => ['10.00', true], 'WC010' => ['10.00', true], 'WC011' => ['15.00', true],
            'WI100' => ['5.00', true], 'WC100' => ['5.00', true], 'WI120' => ['2.00', true], 'WC120' => ['2.00', true],
            'WC158' => ['1.00', true], 'WC160' => ['2.00', true], 'WC516' => ['15.00', true],
            'WI151' => ['5.00', false], 'WC150' => ['15.00', false], 'WI153' => ['15.00', false],
            'WI156' => ['0.50', false], 'WC640' => ['1.00', false], 'WC690' => ['1.00', false], 'WI130' => ['15.00', false],
        ];
        foreach ($expect as $code => [$rate, $active]) {
            $this->assertSame([$rate, $active], [$all[$code]['rate'], $all[$code]['is_active']], $code);
        }
        $this->assertCount(84, $all); // 96 on the schedule minus 12 electric-utility codes
        $this->assertCount(28, $all->where('is_active', true));
        $this->assertArrayNotHasKey('WI650', $all->all());
        $this->assertSame(
            'Professional fees (lawyers, CPAs, engineers, etc.) - corporate, gross income > ₱720K',
            $all['WC011']['name'],
        );
    }

    public function test_vatable_sale_with_customer_withholding_posts(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        // Services sold for 10,000 + 12% VAT; the customer withheld 2% (WC160) and paid the rest.
        $id = $this->postJson('/api/v1/journal-entries', $this->entry([
            ['ar', '11000.00', 0],
            ['cwt', '200.00', 0],
            ['sales', 0, '10000.00', 'VAT12', 'WC160'],
            ['output_vat', 0, '1200.00'],
        ]))->assertCreated()
            ->assertJsonPath('data.lines.2.vat_code.code', 'VAT12')
            ->assertJsonPath('data.lines.2.ewt_code.code', 'WC160')
            ->json('data.id');

        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();
    }

    public function test_purchase_with_vat_and_withholding_on_the_expense_line(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        // Professional fee 50,000 + 12% input VAT; we withhold 5% (WI010) = 2,500 and owe the rest.
        $lines = fn (string $ewt, string $ap) => [
            ['rent', '50000.00', 0, 'VAT12', 'WI010'],
            ['input_vat', '6000.00', 0],
            ['ewt', 0, $ewt],
            ['ap', 0, $ap],
        ];
        $id = $this->postJson('/api/v1/journal-entries', $this->entry($lines('2500.00', '53500.00')))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();

        // Withholding booked on the wrong side.
        $this->postJson('/api/v1/journal-entries', $this->entry([
            ['rent', '50000.00', 0, null, 'WI010'], ['ewt', '2500.00', 0], ['ap', 0, '52500.00'],
        ]))->assertStatus(422)
            ->assertJsonPath('message', 'Expanded withholding tax payable on this entry should be a credit of 2,500.00 (from the tax codes), but the entry has 2,500.00 debit.');

        // Missing the VAT line entirely.
        $this->postJson('/api/v1/journal-entries', $this->entry([['rent', '50000.00', 0, 'VAT12'], ['ap', 0, '50000.00']]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Input VAT on this entry should be a debit of 6,000.00 (from the tax codes), but the entry has none.')
            ->assertJsonPath('errors.lines.0', 'Add a debit line to an account tagged "Input VAT" for 6,000.00.');
    }

    public function test_codes_must_match_their_field(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        $payload = $this->entry([['rent', '100', 0], ['cash', 0, '100']]);
        $payload['lines'][0]['vat_code_id'] = $this->code('WC158');
        $payload['lines'][0]['ewt_code_id'] = $this->code('VAT12');
        $this->postJson('/api/v1/journal-entries', $payload)->assertStatus(422)
            ->assertJsonValidationErrors(['lines.0.vat_code_id', 'lines.0.ewt_code_id']);
    }

    public function test_ewt_on_expense_line_requires_credit_to_payable_within_one_peso(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        // Professional fee 12,345.67 at 5% = 617.28; we pay the rest.
        $entry = fn (string $ewt, string $cash) => $this->entry([
            ['rent', '12345.67', 0, null, 'WI010'],
            ['ewt', 0, $ewt],
            ['cash', 0, $cash],
        ]);
        $this->postJson('/api/v1/journal-entries', $entry('617.00', '11728.67'))->assertCreated(); // within ₱1
        $this->postJson('/api/v1/journal-entries', $entry('615.00', '11730.67'))->assertStatus(422);
    }

    public function test_zero_rated_and_exempt_need_no_tax_line(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        foreach (['VAT0', 'VATEX'] as $code) {
            $this->postJson('/api/v1/journal-entries', $this->entry([['ar', '5000', 0], ['sales', 0, '5000', $code]]))->assertCreated();
        }
    }

    public function test_sales_return_debits_output_vat(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        $this->postJson('/api/v1/journal-entries', $this->entry([
            ['sales', '1000.00', 0, 'VAT12'],
            ['output_vat', '120.00', 0],
            ['ar', 0, '1120.00'],
        ]))->assertCreated();
    }

    public function test_tax_code_cannot_sit_on_the_tax_account(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        $this->postJson('/api/v1/journal-entries', $this->entry([
            ['rent', '1000', 0], ['input_vat', '120', 0, 'VAT12'], ['cash', 0, '1120'],
        ]))->assertStatus(422)
            ->assertJsonPath('message', 'Put tax code VAT12 on the sale or purchase line, not on the tax account 1420.');
    }

    public function test_non_vat_company_cannot_charge_output_vat(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        Company::create(['name' => 'Sari-sari', 'tin' => '123-456-789-000', 'rdo_code' => '044', 'address' => 'Taguig City', 'vat_registered' => false, 'currency' => 'PHP', 'fiscal_year_start_month' => 1]);

        $this->postJson('/api/v1/journal-entries', $this->entry([
            ['ar', '1120', 0], ['sales', 0, '1000', 'VAT12'], ['output_vat', 0, '120'],
        ]))->assertStatus(422)->assertJsonPath('message', "The company isn't VAT-registered, so it can't charge output VAT (VAT12).");

        // Purchases with input VAT are still fine.
        $this->postJson('/api/v1/journal-entries', $this->entry([
            ['rent', '1000', 0, 'VAT12'], ['input_vat', '120', 0], ['cash', 0, '1120'],
        ]))->assertCreated();
    }

    public function test_inactive_code_is_rejected(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        TaxCode::where('code', 'VATEX')->update(['is_active' => false]);
        $this->postJson('/api/v1/journal-entries', $this->entry([['ar', '5000', 0], ['sales', 0, '5000', 'VATEX']]))
            ->assertStatus(422)->assertJsonPath('message', 'Tax code VATEX is inactive.');
    }

    public function test_reversal_keeps_tax_codes_and_balances_tax_accounts(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->chart();
        $id = $this->postJson('/api/v1/journal-entries', $this->entry([
            ['ar', '1120', 0], ['sales', 0, '1000', 'VAT12'], ['output_vat', 0, '120'],
        ]))->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();

        $rev = $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Cancelled sale', 'entry_date' => '2026-10-20'])
            ->assertCreated()->json('data');
        $sale = collect($rev['lines'])->firstWhere('account_id', $this->a['sales']->id);
        $this->assertSame([1000_00, 'VAT12'], [$sale['debit'], $sale['vat_code']['code']]);
    }

    public function test_admin_manages_codes_and_used_codes_are_locked(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->postJson('/api/v1/tax-codes', ['code' => 'XC120', 'name' => 'Custom 2% code', 'kind' => 'ewt', 'rate' => '2'])->assertForbidden();

        $this->actingAsRole(Role::Admin);
        $this->chart();
        $this->postJson('/api/v1/tax-codes', ['code' => 'VAT5', 'name' => 'Odd VAT', 'kind' => 'vat', 'rate' => '5'])
            ->assertStatus(422)->assertJsonValidationErrors('vat_category');
        $new = $this->postJson('/api/v1/tax-codes', ['code' => 'XC120', 'name' => 'Custom 2% code', 'kind' => 'ewt', 'rate' => '2.5', 'vat_category' => 'vatable'])
            ->assertCreated()
            ->assertJsonPath('data.rate_bp', 250)
            ->assertJsonPath('data.rate', '2.50')
            ->assertJsonPath('data.vat_category', null)
            ->json('data.id');
        $this->putJson("/api/v1/tax-codes/{$new}", ['rate' => '2'])->assertOk()->assertJsonPath('data.rate_bp', 200);

        // Use VAT12, then try to change its rate.
        $this->postJson('/api/v1/journal-entries', $this->entry([['rent', '100', 0, 'VAT12'], ['input_vat', '12', 0], ['cash', 0, '112']]))->assertCreated();
        $vat12 = $this->code('VAT12');
        $this->putJson("/api/v1/tax-codes/{$vat12}", ['rate' => '10'])->assertStatus(422)->assertJsonValidationErrors('rate');
        $this->putJson("/api/v1/tax-codes/{$vat12}", ['name' => 'VAT 12% (regular)', 'is_active' => false])->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertSame(1, collect($this->getJson('/api/v1/tax-codes')->json('data'))->firstWhere('code', 'VAT12')['lines_count']);
    }

    public function test_accounts_take_a_tax_role_and_the_ph_chart_tags_its_tax_accounts(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->postJson('/api/v1/accounts/load-defaults')->assertSuccessful();
        $roles = collect($this->getJson('/api/v1/accounts')->json('data'))->whereNotNull('tax_role')->pluck('tax_role', 'code')->all();
        $this->assertEquals(['1420' => 'input_vat', '1430' => 'cwt_receivable', '2210' => 'output_vat', '2230' => 'ewt_payable'], $roles);

        $this->postJson('/api/v1/accounts', ['code' => '2215', 'name' => 'Deferred Output VAT', 'type' => 'liability', 'tax_role' => 'output_vat'])
            ->assertCreated()->assertJsonPath('data.tax_role', 'output_vat');
        $this->postJson('/api/v1/accounts', ['code' => '2216', 'name' => 'Bad', 'type' => 'liability', 'tax_role' => 'sales_tax'])
            ->assertStatus(422)->assertJsonValidationErrors('tax_role');
    }
}
