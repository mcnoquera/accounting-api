<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ControlType;
use App\Enums\Role;
use App\Enums\TaxRole;
use App\Models\Account;
use App\Models\Contact;
use App\Models\TaxCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithholdingSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole(Role::Accountant);
        $make = fn (string $code, AccountType $type, ?TaxRole $role = null, ?ControlType $control = null) => Account::factory()->type($type)->create(['code' => $code, 'tax_role' => $role, 'control_type' => $control]);
        $this->a = [
            'cash' => $make('1110', AccountType::Asset),
            'ar' => $make('1210', AccountType::Asset, null, ControlType::Receivable),
            'cwt' => $make('1430', AccountType::Asset, TaxRole::CwtReceivable),
            'ap' => $make('2110', AccountType::Liability, null, ControlType::Payable),
            'ewt' => $make('2230', AccountType::Liability, TaxRole::EwtPayable),
            'sales' => $make('4010', AccountType::Revenue),
            'rent' => $make('6010', AccountType::Expense),
            'fees' => $make('6020', AccountType::Expense),
        ];
    }

    /** @param list<array{0:string, 1:string|int, 2:string|int, 3?:?string, 4?:?Contact}> $lines [account, debit, credit, EWT code, contact] */
    private function book(string $date, array $lines): int
    {
        $code = fn (?string $c) => $c ? TaxCode::where('code', $c)->value('id') : null;
        $id = $this->postJson('/api/v1/journal-entries', [
            'entry_date' => $date, 'description' => "Entry {$date}",
            'lines' => array_map(fn ($l) => [
                'account_id' => $this->a[$l[0]]->id, 'debit' => (string) $l[1], 'credit' => (string) $l[2],
                'ewt_code_id' => $code($l[3] ?? null), 'contact_id' => isset($l[4]) ? $l[4]->id : null,
            ], $lines),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();

        return $id;
    }

    public function test_quarter_withholding_by_atc_month_payee_and_received(): void
    {
        $landlord = Contact::factory()->supplier()->create(['name' => 'Landlord Inc', 'tin' => '111-111-111-000']);
        $lawyer = Contact::factory()->supplier()->create(['name' => 'Atty. Reyes', 'tin' => '222-222-222-000']);
        $client = Contact::factory()->create(['name' => 'Client Corp', 'tin' => '333-333-333-000']);

        // July rent 50,000 at 5% (WC100); payee from the AP line.
        $this->book('2026-07-05', [['rent', '50000.00', 0, 'WC100'], ['ewt', 0, '2500.00'], ['ap', 0, '47500.00', null, $landlord]]);
        // August rent again; payee on the base line itself, paid in cash.
        $this->book('2026-08-05', [['rent', '50000.00', 0, 'WC100', $landlord], ['ewt', 0, '2500.00'], ['cash', 0, '47500.00']]);
        // September legal fees 20,000 at 10% (WI011).
        $this->book('2026-09-10', [['fees', '20000.00', 0, 'WI011'], ['ewt', 0, '2000.00'], ['ap', 0, '18000.00', null, $lawyer]]);
        // Client withheld 2% (WC160) on our 10,000 service.
        $this->book('2026-08-20', [['ar', '9800.00', 0, null, $client], ['cwt', '200.00', 0], ['sales', 0, '10000.00', 'WC160']]);
        // Remitting July's tax: uncoded, listed separately.
        $this->book('2026-08-10', [['ewt', '2500.00', 0], ['cash', 0, '2500.00']]);
        // Outside the quarter.
        $this->book('2026-10-01', [['rent', '50000.00', 0, 'WC100', $landlord], ['ewt', 0, '2500.00'], ['cash', 0, '47500.00']]);

        $w = $this->getJson('/api/v1/reports/withholding?year=2026&quarter=3')->assertOk()->json('data');

        $byAtc = collect($w['withheld']['by_atc'])->keyBy('atc');
        $this->assertSame(['WC100', 'WI011'], $byAtc->keys()->all());
        $this->assertSame([10000000, 500000], [$byAtc['WC100']['base'], $byAtc['WC100']['tax']]);
        $this->assertSame([250000, 250000, 0], array_column($byAtc['WC100']['months'], 'tax'));
        $this->assertSame([250000, 250000, 200000], array_column($w['withheld']['months'], 'tax'));
        $this->assertSame(['base' => 12000000, 'tax' => 700000], $w['withheld']['total']);
        $this->assertSame(700000, $w['withheld']['booked']);

        $this->assertSame(
            [['Atty. Reyes', '222-222-222-000', 'WI011', 2000000, 200000], ['Landlord Inc', '111-111-111-000', 'WC100', 10000000, 500000]],
            array_map(fn ($p) => [$p['name'], $p['tin'], $p['atc'], $p['base'], $p['tax']], $w['withheld']['payees']),
        );
        $this->assertSame(0, $w['withheld']['payees_without_contact']);

        $this->assertSame([['Client Corp', 'WC160', 1000000, 20000]], array_map(fn ($r) => [$r['name'], $r['atc'], $r['base'], $r['tax']], $w['received']['by_customer']));
        $this->assertSame([20000, 20000], [$w['received']['total']['tax'], $w['received']['booked']]);

        $this->assertCount(1, $w['other_activity']);
        $this->assertSame(-250000, $w['other_activity'][0]['amount']);
    }

    public function test_reversal_nets_out_and_payee_can_be_missing(): void
    {
        $id = $this->book('2026-01-15', [['fees', '1000.00', 0, 'WI010'], ['ewt', 0, '50.00'], ['cash', 0, '950.00']]);
        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Wrong supplier', 'entry_date' => '2026-02-01'])->assertCreated();
        $this->book('2026-03-01', [['fees', '2000.00', 0, 'WI010'], ['ewt', 0, '100.00'], ['cash', 0, '1900.00']]);

        $w = $this->getJson('/api/v1/reports/withholding?year=2026&quarter=1')->json('data');
        $this->assertSame([5000, -5000, 10000], array_column($w['withheld']['months'], 'tax'));
        $this->assertSame(['base' => 200000, 'tax' => 10000], $w['withheld']['total']);
        $this->assertSame([null, 1], [$w['withheld']['payees'][0]['name'], $w['withheld']['payees_without_contact']]);
    }

    public function test_exports_for_returns_and_qap(): void
    {
        $s = Contact::factory()->supplier()->create(['name' => 'Supplier One', 'tin' => '444-444-444-000']);
        $this->book('2026-11-03', [['rent', '10000.00', 0, 'WC100'], ['ewt', 0, '500.00'], ['ap', 0, '9500.00', null, $s]]);

        $csv = $this->get('/api/v1/reports/withholding-payees/export?format=csv&year=2026&quarter=4')->assertOk()->getContent();
        $this->assertStringContainsString('TIN,Payee,ATC,"Nature of income payment",Rate,"Amount of income payment","Tax withheld"', $csv);
        $this->assertStringContainsString('444-444-444-000,"Supplier One",WC100,', $csv);
        $this->assertStringContainsString(',5.00%,10000.00,500.00', $csv);

        $summary = $this->get('/api/v1/reports/withholding/export?format=csv&year=2026&quarter=4')->assertOk()->getContent();
        $this->assertStringContainsString('ATC,"Nature of income payment",Rate,"Oct base","Oct tax","Nov base","Nov tax","Dec base","Dec tax","Quarter base","Quarter tax"', $summary);
        $this->assertStringStartsWith('%PDF-', $this->get('/api/v1/reports/withholding/export?format=pdf&year=2026&quarter=4')->getContent());
        $this->getJson('/api/v1/reports/withholding?quarter=0')->assertStatus(422);
    }
}
