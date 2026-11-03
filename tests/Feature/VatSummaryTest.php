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

class VatSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole(Role::Accountant);
        $make = fn (string $code, AccountType $type, ?TaxRole $role = null) => Account::factory()->type($type)->create(['code' => $code, 'tax_role' => $role]);
        $this->a = [
            'cash' => $make('1110', AccountType::Asset),
            'ar' => $make('1210', AccountType::Asset),
            'input_vat' => $make('1420', AccountType::Asset, TaxRole::InputVat),
            'ap' => $make('2110', AccountType::Liability),
            'output_vat' => $make('2210', AccountType::Liability, TaxRole::OutputVat),
            'vat_payable' => $make('2220', AccountType::Liability),
            'ewt' => $make('2230', AccountType::Liability, TaxRole::EwtPayable),
            'sales' => $make('4010', AccountType::Revenue),
            'supplies' => $make('6010', AccountType::Expense),
        ];
    }

    /** @param list<array{0:string, 1:string|int, 2:string|int, 3?:?string, 4?:string}> $lines */
    private function book(string $date, array $lines): int
    {
        $code = fn (?string $c) => $c ? TaxCode::where('code', $c)->value('id') : null;
        $id = $this->postJson('/api/v1/journal-entries', [
            'entry_date' => $date,
            'description' => "Entry {$date}",
            'lines' => array_map(fn ($l) => [
                'account_id' => $this->a[$l[0]]->id, 'debit' => (string) $l[1], 'credit' => (string) $l[2],
                'vat_code_id' => $code($l[3] ?? null), 'ewt_code_id' => $code($l[4] ?? null),
            ], $lines),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();

        return $id;
    }

    private function sale(string $date, string $net, string $vat, string $code = 'VAT12'): int
    {
        $gross = bcadd($net, $vat, 2);
        $lines = [['ar', $gross, 0], ['sales', 0, $net, $code]];
        if ((float) $vat > 0) {
            $lines[] = ['output_vat', 0, $vat];
        }

        return $this->book($date, $lines);
    }

    public function test_quarter_summary_by_category_and_month(): void
    {
        // Q4 2026 (Oct–Dec), calendar quarter.
        $this->sale('2026-10-05', '10000.00', '1200.00');
        $this->sale('2026-10-20', '5000.00', '0', 'VAT0');
        $this->sale('2026-11-02', '2000.00', '0', 'VATEX');
        $this->sale('2026-12-15', '1000.00', '120.00');
        // Purchase of supplies with input VAT and 1% withholding (WC158).
        $this->book('2026-11-10', [
            ['supplies', '3000.00', 0, 'VAT12', 'WC158'], ['input_vat', '360.00', 0], ['ewt', 0, '30.00'], ['ap', 0, '3330.00'],
        ]);
        // Sales return in December: reduces vatable sales and output VAT.
        $this->book('2026-12-20', [['sales', '500.00', 0, 'VAT12'], ['output_vat', '60.00', 0], ['ar', 0, '560.00']]);
        // Outside the quarter: ignored.
        $this->sale('2026-09-30', '9999.00', '1199.88');
        $this->sale('2027-01-01', '9999.00', '1199.88');

        $res = $this->getJson('/api/v1/reports/vat-summary?year=2026&quarter=4')->assertOk()
            ->assertJsonPath('data.from', '2026-10-01')
            ->assertJsonPath('data.to', '2026-12-31')
            ->json('data');

        $t = $res['total'];
        $this->assertSame(['vatable' => 1050000, 'zero_rated' => 500000, 'exempt' => 200000, 'total' => 1750000], $t['sales']);
        $this->assertSame(['vatable' => 300000, 'zero_rated' => 0, 'exempt' => 0, 'total' => 300000], $t['purchases']);
        $this->assertSame([126000, 36000, 90000], [$t['output_vat'], $t['input_vat'], $t['net_vat_payable']]);
        $this->assertSame([126000, 36000], [$t['output_vat_from_codes'], $t['input_vat_from_codes']]);

        $this->assertSame(['2026-10', '2026-11', '2026-12'], array_column($res['months'], 'month'));
        $this->assertSame([120000, 0, 6000], array_column($res['months'], 'output_vat'));
        $this->assertSame([0, 36000, 0], array_column($res['months'], 'input_vat'));
        $this->assertSame(-36000, $res['months'][1]['net_vat_payable']); // excess input VAT in November
        $this->assertSame([], $res['other_vat_activity']);
    }

    public function test_drafts_voids_and_reversals(): void
    {
        $this->sale('2026-04-10', '1000.00', '120.00');
        $reversed = $this->sale('2026-04-11', '2000.00', '240.00');
        $this->postJson("/api/v1/journal-entries/{$reversed}/reverse", ['reason' => 'Cancelled sale', 'entry_date' => '2026-05-01'])->assertCreated();
        $voided = $this->sale('2026-04-12', '3000.00', '360.00');
        $this->postJson("/api/v1/journal-entries/{$voided}/void", ['reason' => 'Duplicate entry'])->assertOk();
        // Draft only.
        $this->postJson('/api/v1/journal-entries', ['entry_date' => '2026-04-13', 'description' => 'Draft', 'lines' => [
            ['account_id' => $this->a['ar']->id, 'debit' => '100'], ['account_id' => $this->a['sales']->id, 'credit' => '100'],
        ]])->assertCreated();

        $t = $this->getJson('/api/v1/reports/vat-summary?year=2026&quarter=2')->json('data.total');
        $this->assertSame([100000, 12000], [$t['sales']['vatable'], $t['output_vat']]);
    }

    public function test_uncoded_vat_account_activity_is_listed_separately(): void
    {
        $this->sale('2026-01-15', '10000.00', '1200.00');
        // Quarter-end settlement moves output VAT to VAT payable: no VAT code on the entry.
        $settle = $this->book('2026-03-31', [['output_vat', '1200.00', 0], ['vat_payable', 0, '1200.00']]);

        $res = $this->getJson('/api/v1/reports/vat-summary?year=2026&quarter=1')->json('data');
        $this->assertSame(120000, $res['total']['output_vat']);
        $this->assertCount(1, $res['other_vat_activity']);
        $this->assertEquals(
            ['entry_id' => $settle, 'role' => 'output_vat', 'amount' => -120000, 'account_code' => '2210'],
            array_intersect_key($res['other_vat_activity'][0], array_flip(['entry_id', 'role', 'amount', 'account_code'])),
        );
        $this->assertSame(['output_vat' => -120000, 'input_vat' => 0], $res['other_vat_activity_total']);
    }

    public function test_defaults_to_current_quarter_and_validates(): void
    {
        $this->travelTo('2026-08-15');
        $this->getJson('/api/v1/reports/vat-summary')->assertOk()
            ->assertJsonPath('data.year', 2026)->assertJsonPath('data.quarter', 3)
            ->assertJsonPath('data.vat_registered', null);
        $this->getJson('/api/v1/reports/vat-summary?quarter=5')->assertStatus(422)->assertJsonValidationErrors('quarter');

        Company::create(['name' => 'Co', 'tin' => '123-456-789-000', 'address' => 'Manila', 'vat_registered' => true, 'currency' => 'PHP', 'fiscal_year_start_month' => 7]);
        // Calendar quarters regardless of the fiscal year start.
        $this->getJson('/api/v1/reports/vat-summary?year=2026&quarter=1')
            ->assertJsonPath('data.from', '2026-01-01')
            ->assertJsonPath('data.vat_registered', true);
    }
}
