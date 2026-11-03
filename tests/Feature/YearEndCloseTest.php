<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYearClose;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YearEndCloseTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Account> */
    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2027-02-10 09:00:00');
        $this->actingAsRole(Role::Accountant);
        $make = fn (string $code, AccountType $type) => Account::factory()->type($type)->create(['code' => $code]);
        $this->a = [
            'cash' => $make('1110', AccountType::Asset),
            'capital' => $make('3100', AccountType::Equity),
            're' => $make('3200', AccountType::Equity),
            'sales' => $make('4010', AccountType::Revenue),
            'other_income' => $make('4020', AccountType::Revenue),
            'rent' => $make('6010', AccountType::Expense),
        ];
    }

    private function book(string $date, string $debit, string $credit, string $amount): int
    {
        $id = $this->postJson('/api/v1/journal-entries', $this->entryPayload([
            [$this->a[$debit], $amount, 0], [$this->a[$credit], 0, $amount],
        ], date: $date))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/journal-entries/{$id}/post")->assertOk();

        return $id;
    }

    private function closeMonths(string $from, string $to): void
    {
        for ($m = CarbonImmutable::parse("{$from}-01"); $m->format('Y-m') <= $to; $m = $m->addMonth()) {
            $this->postJson('/api/v1/periods/'.$m->format('Y-m').'/close')->assertOk();
        }
    }

    private function seedYear(): void
    {
        $this->book('2026-01-05', 'cash', 'capital', '10000.00');
        $this->book('2026-03-15', 'cash', 'sales', '8000.00');
        $this->book('2026-06-20', 'cash', 'other_income', '500.00');
        $this->book('2026-09-30', 'rent', 'cash', '3000.00');
        $this->book('2027-01-15', 'cash', 'sales', '1000.00'); // next year
    }

    public function test_close_moves_profit_to_retained_earnings_and_keeps_reports_right(): void
    {
        $this->seedYear();
        $this->postJson('/api/v1/fiscal-years/2026/close', ['retained_earnings_account_id' => $this->a['re']->id])->assertStatus(422)
            ->assertJsonPath('message', 'Close every month of FY 2026 first (through Dec 2026).');

        $this->closeMonths('2026-01', '2026-12');
        $bsBefore = $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')->json('data');

        $close = $this->postJson('/api/v1/fiscal-years/2026/close', ['retained_earnings_account_id' => $this->a['re']->id])->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.net_income', 550000)
            ->json('data');

        $entry = JournalEntry::with('lines')->find($close['closing_entry_id']);
        $this->assertTrue($entry->is_closing);
        $this->assertSame(['2026-12-31', 'posted'], [$entry->entry_date->format('Y-m-d'), $entry->status->value]);
        $byAccount = $entry->lines->keyBy('account_id');
        $this->assertSame([800000, 50000, 0], [$byAccount[$this->a['sales']->id]->debit, $byAccount[$this->a['other_income']->id]->debit, $byAccount[$this->a['rent']->id]->debit]);
        $this->assertSame([300000, 550000], [$byAccount[$this->a['rent']->id]->credit, $byAccount[$this->a['re']->id]->credit]);

        // Balance sheet totals unchanged; earnings moved from "current" into retained earnings.
        $bsAfter = $this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')->json('data');
        $this->assertSame([$bsBefore['assets']['total'], $bsBefore['total_liabilities_and_equity']], [$bsAfter['assets']['total'], $bsAfter['total_liabilities_and_equity']]);
        $equity = collect($bsAfter['equity']['rows'])->keyBy('name');
        $this->assertSame([550000, 0], [$equity[$this->a['re']->name]['balance'], $equity['Current earnings']['balance']]);

        // The closed year still shows its profit; the next year starts from zero.
        $this->getJson('/api/v1/reports/income-statement?from=2026-01-01&to=2026-12-31')->assertJsonPath('data.net_income', 550000);
        $this->getJson('/api/v1/reports/income-statement?from=2027-01-01&to=2027-12-31')->assertJsonPath('data.net_income', 100000);
        $this->getJson('/api/v1/reports/income-statement?from=2026-01-01&to=2027-12-31')->assertJsonPath('data.net_income', 650000);

        // Trial balance after close: revenue and expense accounts are zero at year end.
        $tb = collect($this->getJson('/api/v1/reports/trial-balance?as_of=2026-12-31')->json('data.rows'))->keyBy('account_id');
        $this->assertSame([0, 0], [$tb[$this->a['sales']->id]['debit'], $tb[$this->a['sales']->id]['credit']]);
        $this->assertSame(550000, $tb[$this->a['re']->id]['credit']);

        $this->postJson('/api/v1/fiscal-years/2026/close', ['retained_earnings_account_id' => $this->a['re']->id])->assertStatus(422)
            ->assertJsonPath('message', 'FY 2026 is already closed.');
        $this->assertSame(1, AuditLog::query()->where('auditable_type', 'FiscalYearClose')->where('action', 'created')->count());
    }

    public function test_closing_entries_cannot_be_voided_or_reversed_by_hand(): void
    {
        $this->seedYear();
        $this->closeMonths('2026-01', '2027-01');
        $id = $this->postJson('/api/v1/fiscal-years/2026/close', ['retained_earnings_account_id' => $this->a['re']->id])->json('data.closing_entry_id');

        $this->postJson("/api/v1/journal-entries/{$id}/reverse", ['reason' => 'Undo the close', 'entry_date' => '2027-02-01'])->assertStatus(422)
            ->assertJsonPath('message', "Year-end closing entries are managed by the year-end close. Reopen the year's last period to undo it.");
    }

    public function test_reopening_the_last_month_reverses_the_close(): void
    {
        $this->seedYear();
        $this->closeMonths('2026-01', '2026-12');
        $closing = $this->postJson('/api/v1/fiscal-years/2026/close', ['retained_earnings_account_id' => $this->a['re']->id])->json('data.closing_entry_id');

        $this->postJson('/api/v1/periods/2026-12/reopen', ['reason' => 'Late December bill'])->assertOk();

        $this->assertSame(FiscalYearClose::REOPENED, FiscalYearClose::query()->value('status'));
        $reversal = JournalEntry::query()->where('reversal_of_id', $closing)->sole();
        $this->assertTrue($reversal->is_closing);
        $this->assertSame('2026-12-31', $reversal->entry_date->format('Y-m-d'));

        // Revenue is back on the books; record the late bill and close again.
        $this->book('2026-12-20', 'rent', 'cash', '500.00');
        $this->postJson('/api/v1/periods/2026-12/close')->assertOk();
        $this->postJson('/api/v1/fiscal-years/2026/close', ['retained_earnings_account_id' => $this->a['re']->id])->assertOk()
            ->assertJsonPath('data.net_income', 500000);

        $re = collect($this->getJson('/api/v1/reports/balance-sheet?as_of=2026-12-31')->json('data.equity.rows'))->firstWhere('name', $this->a['re']->name);
        $this->assertSame(500000, $re['balance']);
        $this->getJson('/api/v1/reports/income-statement?from=2026-01-01&to=2026-12-31')->assertJsonPath('data.net_income', 500000);
    }

    public function test_list_fiscal_years_and_validation(): void
    {
        Company::create(['name' => 'Co', 'tin' => '123-456-789-000', 'address' => 'Manila', 'vat_registered' => true, 'currency' => 'PHP', 'fiscal_year_start_month' => 7]);
        $this->book('2025-08-01', 'cash', 'sales', '100.00');

        $years = $this->getJson('/api/v1/fiscal-years')->assertOk()->json('data');
        $this->assertSame([2026, 2025], array_column($years, 'fiscal_year'));
        $this->assertSame(['2025-07-01', '2026-06-30', 'open', false, 10000], [$years[1]['start_date'], $years[1]['end_date'], $years[1]['status'], $years[1]['can_close'], $years[1]['net_income']]);

        $this->closeMonths('2025-08', '2026-06');
        $this->postJson('/api/v1/fiscal-years/2025/close', ['retained_earnings_account_id' => $this->a['sales']->id])->assertStatus(422)
            ->assertJsonValidationErrors('retained_earnings_account_id');
        $this->assertTrue($this->getJson('/api/v1/fiscal-years')->json('data.1.can_close'));

        $this->actingAsRole(Role::Auditor);
        $this->postJson('/api/v1/fiscal-years/2025/close', ['retained_earnings_account_id' => $this->a['re']->id])->assertForbidden();
    }

    public function test_earlier_years_close_first(): void
    {
        $this->book('2025-05-01', 'cash', 'sales', '100.00');
        $this->seedYear();
        $this->closeMonths('2025-05', '2026-12');
        $this->postJson('/api/v1/fiscal-years/2026/close', ['retained_earnings_account_id' => $this->a['re']->id])->assertStatus(422)
            ->assertJsonPath('message', 'Close the earlier fiscal years first.');
        $this->postJson('/api/v1/fiscal-years/2025/close', ['retained_earnings_account_id' => $this->a['re']->id])->assertOk();
        $this->postJson('/api/v1/fiscal-years/2026/close', ['retained_earnings_account_id' => $this->a['re']->id])->assertOk();
    }
}
