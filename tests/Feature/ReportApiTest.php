<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    private array $acc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole(Role::Admin);
        $this->acc = $this->basicAccounts();
        $a = $this->acc;
        $this->postEntry([[$a['asset'], '1000', 0], [$a['equity'], 0, '1000']], '2026-01-01');   // capital
        $this->postEntry([[$a['asset'], '500', 0], [$a['revenue'], 0, '500']], '2026-02-01');    // sale
        $this->postEntry([[$a['expense'], '200', 0], [$a['liability'], 0, '200']], '2026-03-01'); // bill
        // Draft and voided entries must not affect reports.
        $this->postJson('/api/v1/journal-entries', $this->entryPayload([[$a['asset'], '999', 0], [$a['revenue'], 0, '999']]));
        $voided = $this->postEntry([[$a['asset'], '777', 0], [$a['revenue'], 0, '777']], '2026-02-15');
        $this->postJson("/api/v1/journal-entries/{$voided}/void", ['reason' => 'Entered in error']);
    }

    private function postEntry(array $lines, string $date): int
    {
        $id = $this->postJson('/api/v1/journal-entries', $this->entryPayload($lines, $date))->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();

        return $id;
    }

    public function test_trial_balance_balances(): void
    {
        $this->getJson('/api/v1/reports/trial-balance')
            ->assertOk()
            ->assertJsonPath('data.totals.debit', 170000)
            ->assertJsonPath('data.totals.credit', 170000)
            ->assertJsonPath('data.balanced', true);
    }

    public function test_trial_balance_as_of_date(): void
    {
        $this->getJson('/api/v1/reports/trial-balance?as_of=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.totals.debit', 100000);
    }

    public function test_balance_sheet_includes_current_earnings(): void
    {
        $this->getJson('/api/v1/reports/balance-sheet')
            ->assertOk()
            ->assertJsonPath('data.assets.total', 150000)
            ->assertJsonPath('data.liabilities.total', 20000)
            ->assertJsonPath('data.equity.total', 130000) // 1000 capital + 300 net income
            ->assertJsonPath('data.balanced', true);
    }

    public function test_income_statement_for_period(): void
    {
        $this->getJson('/api/v1/reports/income-statement?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonPath('data.revenue.total', 50000)
            ->assertJsonPath('data.expenses.total', 20000)
            ->assertJsonPath('data.net_income', 30000);

        $this->getJson('/api/v1/reports/income-statement?from=2026-03-01&to=2026-03-31')
            ->assertJsonPath('data.net_income', -20000);
    }

    public function test_account_ledger_running_balance(): void
    {
        $this->getJson("/api/v1/accounts/{$this->acc['asset']->id}/ledger?from=2026-02-01")
            ->assertOk()
            ->assertJsonPath('data.opening_balance', 100000)
            ->assertJsonCount(1, 'data.rows')
            ->assertJsonPath('data.rows.0.balance', 150000)
            ->assertJsonPath('data.closing_balance', 150000);
    }

    public function test_dashboard_summary(): void
    {
        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.draft_entries', 1)
            ->assertJsonPath('data.books_balanced', true);
    }
}
