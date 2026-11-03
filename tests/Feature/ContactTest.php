<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\ControlType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 09:00:00');
        $make = fn (string $code, AccountType $type, ?ControlType $control = null) => Account::factory()->type($type)->create(['code' => $code, 'control_type' => $control]);
        $this->a = [
            'cash' => $make('1110', AccountType::Asset),
            'ar' => $make('1210', AccountType::Asset, ControlType::Receivable),
            'ap' => $make('2110', AccountType::Liability, ControlType::Payable),
            'sales' => $make('4010', AccountType::Revenue),
            'rent' => $make('6010', AccountType::Expense),
        ];
    }

    /** @param list<array{0:string, 1:string|int, 2:string|int, 3?:?Contact}> $lines */
    private function book(string $date, array $lines, bool $post = true)
    {
        $res = $this->postJson('/api/v1/journal-entries', [
            'entry_date' => $date,
            'description' => "Entry {$date}",
            'lines' => array_map(fn ($l) => [
                'account_id' => $this->a[$l[0]]->id, 'debit' => (string) $l[1], 'credit' => (string) $l[2],
                'contact_id' => isset($l[3]) ? $l[3]->id : null,
            ], $lines),
        ]);
        if ($post && $res->status() === 201) {
            $this->postJson('/api/v1/journal-entries/'.$res->json('data.id').'/post')->assertOk();
        }

        return $res;
    }

    private function invoice(Contact $c, string $date, string $amount): void
    {
        $this->book($date, [['ar', $amount, 0, $c], ['sales', 0, $amount]])->assertCreated();
    }

    private function receipt(Contact $c, string $date, string $amount): void
    {
        $this->book($date, [['cash', $amount, 0], ['ar', 0, $amount, $c]])->assertCreated();
    }

    public function test_crud_normalises_tin_and_filters_by_type(): void
    {
        $this->actingAsRole(Role::Accountant);
        $id = $this->postJson('/api/v1/contacts', ['name' => 'Juan Dela Cruz Trading', 'type' => 'both', 'tin' => '123456789', 'payment_terms_days' => 15])
            ->assertCreated()
            ->assertJsonPath('data.tin', '123-456-789-000')
            ->assertJsonPath('data.payment_terms_days', 15)
            ->json('data.id');
        $this->postJson('/api/v1/contacts', ['name' => 'Bad TIN', 'type' => 'supplier', 'tin' => '12-34'])->assertStatus(422)->assertJsonValidationErrors('tin');
        Contact::factory()->supplier()->create(['name' => 'Ayala Supplies']);

        $this->assertSame(['Juan Dela Cruz Trading'], array_column($this->getJson('/api/v1/contacts?type=customer')->json('data'), 'name'));
        $this->assertSame(['Ayala Supplies', 'Juan Dela Cruz Trading'], array_column($this->getJson('/api/v1/contacts?type=supplier')->json('data'), 'name'));
        $this->putJson("/api/v1/contacts/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAsRole(Role::Auditor);
        $this->getJson('/api/v1/contacts')->assertOk();
        $this->postJson('/api/v1/contacts', ['name' => 'X', 'type' => 'customer'])->assertForbidden();
    }

    public function test_control_accounts_need_the_right_kind_of_active_contact(): void
    {
        $this->actingAsRole(Role::Accountant);
        $customer = Contact::factory()->create(['name' => 'Mabuhay Corp']);
        $supplier = Contact::factory()->supplier()->create(['name' => 'Supplier Inc']);

        $res = $this->book('2026-11-01', [['ar', '100', 0], ['sales', 0, '100']])->assertStatus(422)
            ->assertJsonPath('message', 'Line 1 posts to 1210 '.$this->a['ar']->name.', which needs a customer.');
        $this->assertSame(['Choose the customer for this line.'], $res->json('errors')['lines.0']);
        $this->book('2026-11-01', [['ar', '100', 0, $supplier], ['sales', 0, '100']])->assertStatus(422)
            ->assertJsonPath('message', "Supplier Inc isn't set up as a customer, so it can't be used on 1210.");
        $this->book('2026-11-01', [['ar', '100', 0, $customer], ['sales', 0, '100']])->assertCreated()
            ->assertJsonPath('data.lines.0.contact.name', 'Mabuhay Corp');

        // Non-control lines may carry a contact (e.g. a cash payee) but don't need one.
        $this->book('2026-11-02', [['rent', '50', 0, $supplier], ['cash', 0, '50']])->assertCreated();

        $customer->update(['is_active' => false]);
        $this->book('2026-11-03', [['ar', '100', 0, $customer], ['sales', 0, '100']])->assertStatus(422)
            ->assertJsonPath('message', 'Mabuhay Corp is inactive.');
    }

    public function test_contact_ledger_with_running_balance_and_opening(): void
    {
        $this->actingAsRole(Role::Accountant);
        $c = Contact::factory()->create();
        $other = Contact::factory()->create();
        $this->invoice($c, '2026-09-01', '1000.00');
        $this->invoice($other, '2026-09-02', '999.00');
        $this->receipt($c, '2026-10-05', '400.00');
        $this->invoice($c, '2026-10-20', '250.00');

        $l = $this->getJson("/api/v1/contacts/{$c->id}/ledger?from=2026-10-01")->assertOk()->json('data');
        $this->assertSame(['receivable', 100000], [$l['control_type'], $l['opening_balance']]);
        $this->assertSame([60000, 85000], array_column($l['rows'], 'balance'));
        $this->assertSame(85000, $l['closing_balance']);
    }

    public function test_aging_applies_payments_to_oldest_and_buckets_by_due_date(): void
    {
        $this->actingAsRole(Role::Accountant);
        // As of 2026-11-15 with 30-day terms.
        $c = Contact::factory()->create(['name' => 'Alpha', 'payment_terms_days' => 30]);
        $this->invoice($c, '2026-06-01', '500.00');  // due Jul 1 → 137 days overdue
        $this->invoice($c, '2026-08-20', '300.00');  // due Sep 19 → 57 days
        $this->invoice($c, '2026-10-01', '200.00');  // due Oct 31 → 15 days
        $this->invoice($c, '2026-11-10', '100.00');  // due Dec 10 → current
        $this->receipt($c, '2026-11-12', '600.00');  // clears the 500 and 100 of the 300

        $b = Contact::factory()->create(['name' => 'Beta', 'payment_terms_days' => 0]);
        $this->invoice($b, '2026-09-10', '50.00');   // due Sep 10 → 66 days
        $this->receipt($b, '2026-09-11', '80.00');   // overpaid: 30 unapplied credit

        $a = $this->getJson('/api/v1/reports/aging?type=receivable&as_of=2026-11-15')->assertOk()->json('data');
        $rows = collect($a['rows'])->keyBy('name');

        $this->assertSame(
            ['current' => 10000, 'days_1_30' => 20000, 'days_31_60' => 20000, 'days_61_90' => 0, 'over_90' => 0, 'total' => 50000],
            array_intersect_key($rows['Alpha'], array_flip(['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90', 'total'])),
        );
        $this->assertSame([-3000, -3000], [$rows['Beta']['current'], $rows['Beta']['total']]);
        $this->assertSame(47000, $a['totals']['total']);
        $this->assertSame([47000, 0], [$a['control_balance'], $a['unassigned']]);
    }

    public function test_aging_shows_balance_on_lines_without_a_contact(): void
    {
        $this->actingAsRole(Role::Accountant);
        $c = Contact::factory()->create();
        $this->invoice($c, '2026-11-01', '100.00');
        // An old line from before the account was a control account has no contact.
        $this->a['ar']->update(['control_type' => null]);
        $this->book('2026-10-01', [['ar', '40.00', 0], ['sales', 0, '40.00']])->assertCreated();
        $this->a['ar']->update(['control_type' => 'receivable']);

        $a = $this->getJson('/api/v1/reports/aging?type=receivable')->json('data');
        $this->assertSame([10000, 14000, 4000], [$a['totals']['total'], $a['control_balance'], $a['unassigned']]);
    }

    public function test_payables_aging_and_export(): void
    {
        $this->actingAsRole(Role::Accountant);
        $s = Contact::factory()->supplier()->create(['name' => 'Meralco-ish Supplier', 'tin' => '111-222-333-000']);
        $this->book('2026-10-01', [['rent', '1200.00', 0], ['ap', 0, '1200.00', $s]])->assertCreated();

        $a = $this->getJson('/api/v1/reports/aging?type=payable&as_of=2026-11-15')->json('data');
        $this->assertSame(120000, $a['rows'][0]['days_1_30']);

        $csv = $this->get('/api/v1/reports/aging/export?format=csv&type=payable&as_of=2026-11-15')->assertOk()->getContent();
        $this->assertStringContainsString('ledgerly-payable-aging-', $this->get('/api/v1/reports/aging/export?format=csv&type=payable')->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Payables aging', $csv);
        $this->assertStringContainsString('"Meralco-ish Supplier",111-222-333-000,30,0.00,1200.00,0.00,0.00,0.00,1200.00', $csv);
        $this->getJson('/api/v1/reports/aging/export?format=csv')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->assertStringStartsWith('%PDF-', $this->get('/api/v1/reports/aging/export?format=pdf&type=payable')->getContent());
    }

    public function test_delete_only_unused_contacts(): void
    {
        $this->actingAsRole(Role::Accountant);
        $used = Contact::factory()->create(['name' => 'Used Co']);
        $this->invoice($used, '2026-11-01', '10');
        $unused = Contact::factory()->create();

        $this->deleteJson("/api/v1/contacts/{$used->id}")->assertStatus(422)
            ->assertJsonPath('message', "Used Co is used on journal entries and can't be deleted. Deactivate it instead.");
        $this->deleteJson("/api/v1/contacts/{$unused->id}")->assertOk();
    }

    public function test_control_type_must_match_account_type_and_ph_chart_is_tagged(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->postJson('/api/v1/accounts', ['code' => '2120', 'name' => 'Other AP', 'type' => 'liability', 'control_type' => 'receivable'])
            ->assertStatus(422)->assertJsonPath('errors.control_type.0', 'A receivable control account must be an asset account.');
        $this->postJson('/api/v1/accounts', ['code' => '2120', 'name' => 'Other AP', 'type' => 'liability', 'control_type' => 'payable'])
            ->assertCreated()->assertJsonPath('data.control_type', 'payable');
    }

    public function test_starter_chart_tags_trade_accounts(): void
    {
        $this->actingAsRole(Role::Admin);
        Account::query()->delete();
        $this->postJson('/api/v1/accounts/load-defaults')->assertSuccessful();
        $tagged = collect($this->getJson('/api/v1/accounts')->json('data'))->whereNotNull('control_type')->pluck('control_type', 'code')->all();
        $this->assertEquals(['1210' => 'receivable', '2110' => 'payable'], $tagged);
    }
}
