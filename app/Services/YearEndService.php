<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\EntryStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Models\Company;
use App\Models\FiscalYearClose;
use App\Models\JournalEntry;
use App\Models\User;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Repositories\Contracts\LedgerRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Year-end close: once every month of a fiscal year is closed, a posted closing entry dated
 * the year's last day zeroes each revenue and expense account into retained earnings.
 * Reopening the year's last month reverses that entry. Fiscal years are named by the
 * calendar year they start in (FY 2026 with a July start runs Jul 2026 to Jun 2027).
 */
class YearEndService
{
    public function __construct(
        private PeriodService $periods,
        private LedgerRepositoryInterface $ledger,
        private JournalEntryRepositoryInterface $entries,
    ) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day */
    public function range(int $fiscalYear): array
    {
        $start = CarbonImmutable::create($fiscalYear, $this->startMonth(), 1);

        return [$start, $start->addMonths(11)->endOfMonth()];
    }

    /** Fiscal years from the first one with entries up to the current one, newest first. */
    public function list(): array
    {
        $closes = FiscalYearClose::query()->with(['closingEntry:id,number', 'retainedEarnings:id,code,name', 'closer:id,name'])->get()->keyBy('fiscal_year');
        $first = JournalEntry::query()->min('entry_date');
        $current = $this->fiscalYearOf(now()->toDateString());
        $from = $first ? min($this->fiscalYearOf(substr((string) $first, 0, 10)), $current) : $current;
        $locked = $this->periods->lockedThrough();

        $years = [];
        for ($fy = $current; $fy >= $from; $fy--) {
            [$start, $end] = $this->range($fy);
            $close = $closes->get($fy);
            $closed = $close?->status === FiscalYearClose::CLOSED;
            $years[] = [
                'fiscal_year' => $fy,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'status' => $closed ? 'closed' : 'open',
                'periods_closed' => $locked !== null && $locked >= $end->toDateString(),
                'net_income' => $closed ? $close->net_income : $this->netIncome($start, $end),
                'closing_entry' => $closed ? $close->closingEntry : null,
                'retained_earnings_account' => $close?->retainedEarnings,
                'closed_by' => $closed ? $close->closer : null,
                'closed_at' => $closed ? $close->closed_at : null,
                'can_close' => ! $closed && $locked !== null && $locked >= $end->toDateString() && $this->priorYearsClosed($fy, $closes),
            ];
        }

        return $years;
    }

    public function close(int $fiscalYear, int $retainedEarningsId, User $user): FiscalYearClose
    {
        return DB::transaction(function () use ($fiscalYear, $retainedEarningsId, $user) {
            $existing = FiscalYearClose::query()->lockForUpdate()->where('fiscal_year', $fiscalYear)->first();
            if ($existing?->status === FiscalYearClose::CLOSED) {
                throw new DomainRuleException("FY {$fiscalYear} is already closed.");
            }
            [$start, $end] = $this->range($fiscalYear);
            $locked = $this->periods->lockedThrough();
            if ($locked === null || $locked < $end->toDateString()) {
                throw new DomainRuleException("Close every month of FY {$fiscalYear} first (through {$end->format('M Y')}).");
            }
            if (! $this->priorYearsClosed($fiscalYear, FiscalYearClose::query()->get()->keyBy('fiscal_year'))) {
                throw new DomainRuleException('Close the earlier fiscal years first.');
            }

            $re = Account::query()->find($retainedEarningsId);
            if (! $re || $re->type !== AccountType::Equity || ! $re->is_active || $re->children()->exists()) {
                throw new DomainRuleException('Choose an active equity detail account for retained earnings.', [
                    'retained_earnings_account_id' => ['Must be an active equity account without sub-accounts.'],
                ]);
            }

            $lines = [];
            $net = 0; // credit-positive: profit
            $totals = $this->ledger->accountTotals($start->toDateString(), $end->toDateString(), excludeClosing: true)
                ->filter(fn ($r) => in_array($r->type, [AccountType::Revenue->value, AccountType::Expense->value], true));
            foreach ($totals as $r) {
                $balance = $r->debit - $r->credit; // debit-positive
                if ($balance === 0) {
                    continue;
                }
                $lines[] = [
                    'account_id' => $r->account_id,
                    'debit' => $balance < 0 ? -$balance : 0,
                    'credit' => $balance > 0 ? $balance : 0,
                    'memo' => 'Close to retained earnings',
                ];
                $net -= $balance;
            }

            $entry = null;
            if ($lines !== []) {
                if ($net !== 0) {
                    $lines[] = [
                        'account_id' => $re->id,
                        'debit' => $net < 0 ? -$net : 0,
                        'credit' => $net > 0 ? $net : 0,
                        'memo' => $net > 0 ? 'Net income for the year' : 'Net loss for the year',
                    ];
                }
                $entry = $this->entries->create([
                    'number' => $this->entries->nextNumber(),
                    'entry_date' => $end->toDateString(),
                    'reference' => "FY{$fiscalYear}-CLOSE",
                    'description' => "Year-end closing entry for FY {$fiscalYear}",
                    'status' => EntryStatus::Posted,
                    'created_by' => $user->id,
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                    'is_closing' => true,
                ], $lines);
            }

            $close = $existing ?? new FiscalYearClose(['fiscal_year' => $fiscalYear]);
            $close->fill([
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'status' => FiscalYearClose::CLOSED,
                'retained_earnings_account_id' => $re->id,
                'closing_entry_id' => $entry?->id,
                'net_income' => $net,
                'closed_by' => $user->id,
                'closed_at' => now(),
                'reopened_by' => null,
                'reopened_at' => null,
            ])->save();

            return $close->load(['closingEntry:id,number', 'retainedEarnings:id,code,name']);
        });
    }

    /**
     * Called when a month is reopened: if it's the last month of a closed fiscal year, the
     * closing entry is reversed (dated the same day, also a closing entry) and the year reopened.
     */
    public function reopenForPeriod(string $period, User $user): ?FiscalYearClose
    {
        $close = FiscalYearClose::query()->lockForUpdate()
            ->where('status', FiscalYearClose::CLOSED)
            ->whereDate('end_date', CarbonImmutable::parse($period.'-01')->endOfMonth()->toDateString())
            ->first();
        if (! $close) {
            return null;
        }

        if ($close->closing_entry_id) {
            $original = JournalEntry::query()->with('lines')->findOrFail($close->closing_entry_id);
            $this->entries->create([
                'number' => $this->entries->nextNumber(),
                'entry_date' => $original->entry_date->format('Y-m-d'),
                'reference' => $original->number,
                'description' => "Reversal of {$original->number}: FY {$close->fiscal_year} reopened",
                'status' => EntryStatus::Posted,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'posted_at' => now(),
                'reversal_of_id' => $original->id,
                'is_closing' => true,
            ], $original->lines->map(fn ($l) => [
                'account_id' => $l->account_id, 'debit' => $l->credit, 'credit' => $l->debit, 'memo' => $l->memo,
            ])->all());
        }

        $close->fill([
            'status' => FiscalYearClose::REOPENED,
            'closing_entry_id' => null,
            'reopened_by' => $user->id,
            'reopened_at' => now(),
        ])->save();

        return $close;
    }

    public function fiscalYearOf(string $date): int
    {
        $d = CarbonImmutable::parse($date);

        return $d->month >= $this->startMonth() ? $d->year : $d->year - 1;
    }

    private function startMonth(): int
    {
        return Company::current()?->fiscal_year_start_month ?? 1;
    }

    private function netIncome(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $this->ledger->accountTotals($start->toDateString(), $end->toDateString(), excludeClosing: true)
            ->reduce(fn ($sum, $r) => in_array($r->type, ['revenue', 'expense'], true) ? $sum + $r->credit - $r->debit : $sum, 0);
    }

    /** Earlier fiscal years that have entries must be closed first. */
    private function priorYearsClosed(int $fiscalYear, $closes): bool
    {
        $first = JournalEntry::query()->where('status', EntryStatus::Posted)->min('entry_date');
        if (! $first) {
            return true;
        }
        for ($fy = $this->fiscalYearOf(substr((string) $first, 0, 10)); $fy < $fiscalYear; $fy++) {
            if ($closes->get($fy)?->status !== FiscalYearClose::CLOSED) {
                return false;
            }
        }

        return true;
    }
}
