<?php

namespace App\Repositories;

use App\Enums\EntryStatus;
use App\Repositories\Contracts\LedgerRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LedgerRepository implements LedgerRepositoryInterface
{
    public function accountTotals(?string $from = null, ?string $to = null): Collection
    {
        return DB::table('accounts')
            ->leftJoin('journal_lines', 'journal_lines.account_id', '=', 'accounts.id')
            ->leftJoin('journal_entries', function ($join) use ($from, $to) {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where('journal_entries.status', EntryStatus::Posted->value);
                if ($from) {
                    $join->whereDate('journal_entries.entry_date', '>=', $from);
                }
                if ($to) {
                    $join->whereDate('journal_entries.entry_date', '<=', $to);
                }
            })
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->select([
                'accounts.id as account_id', 'accounts.code', 'accounts.name', 'accounts.type',
                DB::raw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.debit ELSE 0 END), 0) as debit'),
                DB::raw('COALESCE(SUM(CASE WHEN journal_entries.id IS NOT NULL THEN journal_lines.credit ELSE 0 END), 0) as credit'),
            ])
            ->get()
            ->map(function ($row) {
                $row->debit = (int) $row->debit;
                $row->credit = (int) $row->credit;

                return $row;
            });
    }

    public function accountLines(int $accountId, ?string $from = null, ?string $to = null): Collection
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.status', EntryStatus::Posted->value)
            ->when($from, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $to))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->select([
                'journal_entries.id as entry_id', 'journal_entries.number', DB::raw('DATE(journal_entries.entry_date) as entry_date'),
                'journal_entries.description', 'journal_lines.memo', 'journal_lines.debit', 'journal_lines.credit',
            ])
            ->get()
            ->map(function ($row) {
                $row->debit = (int) $row->debit;
                $row->credit = (int) $row->credit;

                return $row;
            });
    }
}
