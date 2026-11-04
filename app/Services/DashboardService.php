<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Enums\FindingStatus;
use App\Models\Account;
use App\Models\AuditFinding;
use App\Models\JournalEntry;

class DashboardService
{
    public function __construct(private ReportService $reports) {}

    public function summary(): array
    {
        $today = now()->toDateString();
        $yearStart = now()->startOfYear()->toDateString();
        $balance = $this->reports->balanceSheet($today);
        $income = $this->reports->incomeStatement($yearStart, $today);

        return [
            'total_assets' => $balance['assets']['total'],
            'total_liabilities' => $balance['liabilities']['total'],
            'total_equity' => $balance['equity']['total'],
            'ytd_revenue' => $income['revenue']['total'],
            'ytd_expenses' => $income['expenses']['total'],
            'ytd_net_income' => $income['net_income'],
            'draft_entries' => JournalEntry::where('status', EntryStatus::Draft)->count(),
            'open_findings' => AuditFinding::where('status', FindingStatus::Open)->count(),
            'books_balanced' => $balance['balanced'],
            'cash_by_kind' => $this->cashByKind($today),
            'recent_entries' => JournalEntry::with('lines')->latest('id')->limit(5)->get()
                ->map(fn ($e) => [
                    'id' => $e->id, 'number' => $e->number, 'entry_date' => $e->entry_date->toDateString(),
                    'description' => $e->description, 'status' => $e->status->value, 'amount' => $e->totalDebit(),
                ]),
        ];
    }

    /** Balances of cash accounts as of a date, grouped by kind (cash, bank, e-wallet). */
    private function cashByKind(string $asOf): array
    {
        $balances = $this->reports->trialBalance($asOf)['rows']->keyBy('account_id');
        $out = [];
        foreach (Account::query()->where('is_cash', true)->orderBy('code')->get() as $a) {
            $kind = $a->cash_kind?->value ?? 'cash';
            $row = $balances->get($a->id);
            $balance = $row ? $row['debit'] - $row['credit'] : 0;
            $out[$kind] ??= ['kind' => $kind, 'total' => 0, 'accounts' => []];
            $out[$kind]['total'] += $balance;
            $out[$kind]['accounts'][] = ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'provider' => $a->provider, 'balance' => $balance];
        }

        return array_values(array_filter([$out['cash'] ?? null, $out['bank'] ?? null, $out['ewallet'] ?? null]));
    }
}
