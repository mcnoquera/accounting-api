<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\Account;
use App\Repositories\Contracts\LedgerRepositoryInterface;

/**
 * Financial statements built from posted journal lines. All amounts are integer cents.
 */
class ReportService
{
    public function __construct(private LedgerRepositoryInterface $ledger) {}

    public function trialBalance(?string $asOf = null): array
    {
        $rows = $this->ledger->accountTotals(null, $asOf)
            ->filter(fn ($r) => $r->debit !== 0 || $r->credit !== 0)
            ->map(function ($r) {
                $net = $r->debit - $r->credit;

                return [
                    'account_id' => $r->account_id,
                    'code' => $r->code,
                    'name' => $r->name,
                    'type' => $r->type,
                    'debit' => max($net, 0),
                    'credit' => max(-$net, 0),
                ];
            })->values();

        $debit = $rows->sum('debit');
        $credit = $rows->sum('credit');

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'totals' => ['debit' => $debit, 'credit' => $credit],
            'balanced' => $debit === $credit,
        ];
    }

    public function balanceSheet(?string $asOf = null): array
    {
        $totals = $this->ledger->accountTotals(null, $asOf);
        $section = fn (AccountType $type) => $this->section($totals, $type);

        $assets = $section(AccountType::Asset);
        $liabilities = $section(AccountType::Liability);
        $equity = $section(AccountType::Equity);
        $netIncome = $section(AccountType::Revenue)['total'] - $section(AccountType::Expense)['total'];

        $equity['rows'][] = ['account_id' => null, 'code' => '', 'name' => 'Current earnings', 'balance' => $netIncome];
        $equity['total'] += $netIncome;

        $liabilitiesAndEquity = $liabilities['total'] + $equity['total'];

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_liabilities_and_equity' => $liabilitiesAndEquity,
            'balanced' => $assets['total'] === $liabilitiesAndEquity,
        ];
    }

    public function incomeStatement(?string $from = null, ?string $to = null): array
    {
        $totals = $this->ledger->accountTotals($from, $to);
        $revenue = $this->section($totals, AccountType::Revenue);
        $expenses = $this->section($totals, AccountType::Expense);

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_income' => $revenue['total'] - $expenses['total'],
        ];
    }

    public function accountLedger(Account $account, ?string $from = null, ?string $to = null): array
    {
        $opening = 0;
        if ($from) {
            $before = $this->ledger->accountLines($account->id, null, date('Y-m-d', strtotime("$from -1 day")));
            $opening = $account->type->balance((int) $before->sum('debit'), (int) $before->sum('credit'));
        }

        $running = $opening;
        $rows = $this->ledger->accountLines($account->id, $from, $to)->map(function ($r) use (&$running, $account) {
            $running += $account->type->balance($r->debit, $r->credit);

            return [...(array) $r, 'balance' => $running];
        });

        return [
            'account' => $account,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'rows' => $rows,
            'closing_balance' => $running,
        ];
    }

    private function section($totals, AccountType $type): array
    {
        $rows = $totals->where('type', $type->value)
            ->map(fn ($r) => [
                'account_id' => $r->account_id,
                'code' => $r->code,
                'name' => $r->name,
                'balance' => $type->balance($r->debit, $r->credit),
            ])
            ->filter(fn ($r) => $r['balance'] !== 0)
            ->values()
            ->all();

        return ['rows' => $rows, 'total' => array_sum(array_column($rows, 'balance'))];
    }
}
