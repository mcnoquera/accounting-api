<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(array $rows): string
    {
        return implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($c) => str_contains($c, ',') ? "\"{$c}\"" : $c, $r)), $rows));
    }

    public function test_templates_download_with_the_expected_columns(): void
    {
        $this->actingAsRole(Role::Accountant);
        $res = $this->get('/api/v1/imports/accounts/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('code,name,type,parent_code,tax_role,control_type,is_cash,description,is_active', $res->getContent());
        $this->assertStringContainsString('name,type,tin,address,email,phone,payment_terms_days,is_active', $this->get('/api/v1/imports/contacts/template')->getContent());
    }

    public function test_account_preview_shows_actions_and_errors_without_writing(): void
    {
        $this->actingAsRole(Role::Accountant);
        Account::factory()->type(AccountType::Asset)->create(['code' => '1110', 'name' => 'Cash']);

        $csv = $this->csv([
            ['code', 'name', 'type', 'parent_code', 'tax_role', 'control_type', 'is_cash'],
            ['1100', 'Cash and Cash Equivalents', 'asset', '', '', '', 'no'],
            ['1110', 'Cash on Hand', 'asset', '1100', '', '', 'yes'],      // update + new parent
            ['1210', 'Trade Receivables', 'asset', '', '', 'receivable', ''],
            ['2210', 'Output VAT', 'liability', '', 'output_vat', '', ''],
            ['2999', 'Bad', 'liability', '', '', 'receivable', ''],         // error: control type
            ['9999', 'Child', 'expense', '1100', '', '', ''],               // error: parent type
            ['X Y', 'Bad code', 'asset', '', '', '', ''],                   // error: code
        ]);
        $p = $this->postJson('/api/v1/imports/accounts/preview', ['csv' => $csv])->assertOk()->json('data');

        $this->assertSame(['create' => 3, 'update' => 1, 'unchanged' => 0, 'error' => 3], $p['summary']);
        $this->assertSame(['create', 'update', 'create', 'create', 'error', 'error', 'error'], array_column($p['rows'], 'action'));
        $this->assertSame(['a receivable control account must be an asset'], $p['rows'][4]['errors']);
        $this->assertSame(['parent_code 1100 is a asset account'], $p['rows'][5]['errors']);
        $this->assertSame(6, $p['rows'][4]['line']);
        $this->assertSame(1, Account::count());

        $this->postJson('/api/v1/imports/accounts/commit', ['csv' => $csv])->assertStatus(422)
            ->assertJsonPath('message', '3 row(s) have errors. Fix them and preview again; nothing was imported.');
        $this->assertSame(1, Account::count());
    }

    public function test_account_commit_creates_parents_first_and_updates(): void
    {
        $this->actingAsRole(Role::Accountant);
        Account::factory()->type(AccountType::Asset)->create(['code' => '1110', 'name' => 'Cash']);
        $csv = $this->csv([
            ['code', 'name', 'type', 'parent_code', 'is_cash', 'control_type'],
            ['1110', 'Cash on Hand', 'asset', '1100', 'yes', ''],   // child listed before its parent
            ['1100', 'Cash and Cash Equivalents', 'asset', '', 'no', ''],
            ['1210', 'Trade Receivables', 'asset', '', '', 'receivable'],
        ]);
        $this->postJson('/api/v1/imports/accounts/commit', ['csv' => $csv])->assertOk()->assertJsonPath('data.summary.create', 2);

        $cash = Account::where('code', '1110')->first();
        $this->assertSame(['Cash on Hand', true, Account::where('code', '1100')->value('id')], [$cash->name, $cash->is_cash, $cash->parent_id]);
        $this->assertSame('receivable', Account::where('code', '1210')->first()->control_type->value);

        // Re-importing the same file changes nothing.
        $this->postJson('/api/v1/imports/accounts/preview', ['csv' => $csv])->assertJsonPath('data.summary.unchanged', 3);
    }

    public function test_contacts_match_on_tin_then_name(): void
    {
        $this->actingAsRole(Role::Accountant);
        Contact::factory()->create(['name' => 'Old Name Inc.', 'tin' => '200-333-444-000']);
        Contact::factory()->supplier()->create(['name' => 'Visayas Supply Co.']);

        $csv = $this->csv([
            ['name', 'type', 'tin', 'email', 'payment_terms_days'],
            ['Mabuhay Retail Inc.', 'customer', '200333444', 'ap@mabuhay.test', '45'],  // matches by TIN, renames
            ['visayas supply co.', 'supplier', '', '', '15'],                              // matches by name
            ['New Client', 'both', '', '', ''],
            ['Bad', 'vendor', '12', 'nope', '400'],
        ]);
        $p = $this->postJson('/api/v1/imports/contacts/preview', ['csv' => $csv])->json('data');
        $this->assertSame(['update', 'update', 'create', 'error'], array_column($p['rows'], 'action'));
        $this->assertSame(['type: customer, supplier or both', 'tin: 9 digits plus a 3- or 5-digit branch code', 'email: not a valid address', 'payment_terms_days: 0 to 365'], $p['rows'][3]['errors']);

        $fixed = $this->csv([
            ['name', 'type', 'tin', 'email', 'payment_terms_days'],
            ['Mabuhay Retail Inc.', 'customer', '200333444', 'ap@mabuhay.test', '45'],
            ['New Client', 'both', '', '', ''],
        ]);
        $this->postJson('/api/v1/imports/contacts/commit', ['csv' => $fixed])->assertOk();
        $m = Contact::where('tin', '200-333-444-000')->sole();
        $this->assertSame(['Mabuhay Retail Inc.', 45, 'ap@mabuhay.test'], [$m->name, $m->payment_terms_days, $m->email]);
        $this->assertSame(3, Contact::count());
    }

    public function test_bad_files_and_permissions(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->postJson('/api/v1/imports/accounts/preview', ['csv' => "name,type\nCash,asset"])->assertStatus(422)
            ->assertJsonPath('message', 'Missing column(s): code. Download the template for the expected columns.');
        $this->postJson('/api/v1/imports/contacts/preview', ['csv' => "name,type,colour\nA,customer,red"])->assertStatus(422)
            ->assertJsonPath('message', 'Unknown column(s): colour.');
        $this->postJson('/api/v1/imports/payroll/preview', ['csv' => 'x'])->assertNotFound();

        $this->actingAsRole(Role::Auditor);
        $this->postJson('/api/v1/imports/contacts/preview', ['csv' => "name,type\nA,customer"])->assertForbidden();
    }
}
