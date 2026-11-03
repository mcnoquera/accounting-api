<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Exports\Exporter;
use App\Services\Exports\ReportTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    private array $acc;

    private function seedEntries(): void
    {
        $this->acc = $this->basicAccounts();
        foreach ([['2026-03-02', '1250.50'], ['2026-03-15', '99.99']] as [$date, $amount]) {
            $id = $this->postJson('/api/v1/journal-entries', $this->entryPayload([
                [$this->acc['asset'], $amount, 0], [$this->acc['revenue'], 0, $amount],
            ], date: $date, description: '=HYPERLINK("http://evil")'))->json('data.id');
            $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();
        }
    }

    /** @return list<list<string>> */
    private function csv(string $body): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);

        return array_map(fn ($l) => str_getcsv($l, escape: ''), explode("\n", trim(substr($body, 3))));
    }

    public function test_trial_balance_csv_uses_pesos_and_is_audited(): void
    {
        $user = $this->actingAsRole(Role::Accountant);
        $this->seedEntries();

        $res = $this->get('/api/v1/reports/trial-balance/export?format=csv&as_of=2026-03-31')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertMatchesRegularExpression('/attachment; filename="ledgerly-trial-balance-\d{4}-\d{2}-\d{2}\.csv"/', $res->headers->get('Content-Disposition'));

        $rows = $this->csv($res->getContent());
        $this->assertSame(['Trial balance'], $rows[0]);
        $this->assertSame(['As of Mar 31, 2026'], $rows[1]);
        $this->assertSame(['Code', 'Account', 'Type', 'Debit', 'Credit'], $rows[3]);
        $this->assertContains(['1000', $this->acc['asset']->name, 'Asset', '1350.49', '0.00'], $rows);
        $this->assertSame(['', 'Total', '', '1350.49', '1350.49'], end($rows));

        $log = AuditLog::query()->where('action', 'exported')->sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertEquals(['export' => 'trial-balance', 'format' => 'csv', 'filters' => ['format' => 'csv', 'as_of' => '2026-03-31'], 'rows' => 3], $log->new_values);
    }

    public function test_statements_and_vat_summary_export_as_pdf_with_company_header(): void
    {
        $this->actingAsRole(Role::Admin);
        $this->seedEntries();
        Company::create(['name' => 'Bayanihan Trading Corp.', 'tin' => '123-456-789-000', 'address' => 'Makati City', 'vat_registered' => true, 'currency' => 'PHP', 'fiscal_year_start_month' => 1]);

        foreach (['trial-balance', 'balance-sheet', 'income-statement?from=2026-01-01&to=2026-12-31', 'vat-summary?year=2026&quarter=1'] as $report) {
            [$path, $query] = array_pad(explode('?', $report), 2, '');
            $res = $this->get("/api/v1/reports/{$path}/export?format=pdf&{$query}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $res->getContent(), $path);
        }
        $this->assertSame(4, AuditLog::query()->where('action', 'exported')->count());

        // The PDF template carries the company header, period and preparer.
        $html = view('exports.report', [
            'table' => app(ReportTables::class)->incomeStatement('2026-01-01', '2026-12-31'),
            'company' => Company::current(), 'preparedBy' => 'Alex Admin', 'preparedAt' => 'Nov 3, 2026',
            'money' => fn (int $c) => Exporter::pesos($c, true),
        ])->render();
        foreach (['Bayanihan Trading Corp.', 'TIN 123-456-789-000', 'Makati City', 'Jan 1, 2026 to Dec 31, 2026', 'Prepared by Alex Admin', '1,350.49'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_income_statement_and_vat_summary_csv(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->seedEntries();

        $rows = $this->csv($this->get('/api/v1/reports/income-statement/export?format=csv&from=2026-03-01&to=2026-03-31')->assertOk()->getContent());
        $this->assertContains(['', 'Revenue', ''], $rows);
        $this->assertContains(['', 'Net income', '1350.49'], $rows);

        $rows = $this->csv($this->get('/api/v1/reports/vat-summary/export?format=csv&year=2026&quarter=1')->assertOk()->getContent());
        $this->assertSame(['', 'Jan 2026', 'Feb 2026', 'Mar 2026', 'Quarter'], $rows[3]);
        $this->assertContains(['Output VAT', '0.00', '0.00', '0.00', '0.00'], $rows);
    }

    public function test_ledger_csv_has_running_balance_and_neutralises_formulas(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->seedEntries();

        $rows = $this->csv($this->get("/api/v1/accounts/{$this->acc['asset']->id}/ledger/export?from=2026-03-10")->assertOk()->getContent());
        $this->assertSame(['Date', 'Entry', 'Description', 'Memo', 'Debit', 'Credit', 'Balance'], $rows[3]);
        $this->assertSame(['', '', 'Opening balance', '', '', '', '1250.50'], $rows[4]);
        $number = JournalEntry::query()->where('entry_date', '2026-03-15')->value('number');
        $this->assertSame(['2026-03-15', $number, '\'=HYPERLINK("http://evil")', '', '99.99', '0.00', '1350.49'], $rows[5]);
        $this->assertSame(['', '', 'Closing balance', '', '', '', '1350.49'], $rows[6]);
    }

    public function test_audit_log_export_requires_audit_access(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->seedEntries();
        $this->get('/api/v1/audit/logs/export', ['Accept' => 'application/json'])->assertForbidden();

        $auditor = $this->actingAsRole(Role::Auditor);

        $rows = $this->csv($this->get('/api/v1/audit/logs/export?action=created')->assertOk()->getContent());
        $this->assertSame(['Date and time', 'User', 'Email', 'Action', 'Record type', 'Record ID', 'IP address', 'Old values', 'New values'], $rows[3]);
        $this->assertSame('created', $rows[4][3]);
        $this->assertSame('exported', AuditLog::query()->latest('id')->value('action'));
        $this->assertSame($auditor->id, AuditLog::query()->latest('id')->value('user_id'));
    }

    public function test_validation_and_unknown_reports(): void
    {
        $this->actingAsRole(Role::Accountant);
        $this->getJson('/api/v1/reports/trial-balance/export')->assertStatus(422)->assertJsonValidationErrors('format');
        $this->getJson('/api/v1/reports/trial-balance/export?format=xlsx')->assertStatus(422);
        $this->getJson('/api/v1/reports/payroll/export?format=csv')->assertNotFound();
        $this->getJson('/api/v1/reports/vat-summary/export?format=csv&quarter=9')->assertStatus(422);
    }

    public function test_peso_formatting(): void
    {
        $this->assertSame(['0.00', '0.05', '-1234.50', '1,234,567.89', '-0.01'], [
            Exporter::pesos(0), Exporter::pesos(5), Exporter::pesos(-123450), Exporter::pesos(123456789, true), Exporter::pesos(-1),
        ]);
        $this->assertSame(["'-cmd", "'@SUM(A1)", 'Plain', '12'], [
            Exporter::safeText('-cmd'), Exporter::safeText('@SUM(A1)'), Exporter::safeText('Plain'), Exporter::safeText(12),
        ]);
    }
}
