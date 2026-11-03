<?php

namespace App\Services\Exports;

use App\Models\Account;
use App\Models\AuditLog;
use App\Services\ReportService;
use App\Services\VatSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds report tables once so CSV and PDF exports show exactly the same figures.
 *
 * A table is ['title', 'period', 'columns' => list<string>, 'money' => list<int> (money column
 * indexes), 'rows' => list<['cells' => list<mixed>, 'kind' => 'row'|'section'|'total']>,
 * 'notes' => list<string>]. Money cells are integer cents.
 */
class ReportTables
{
    public function __construct(private ReportService $reports, private VatSummaryService $vat) {}

    public function trialBalance(?string $asOf): array
    {
        $tb = $this->reports->trialBalance($asOf);
        $rows = collect($tb['rows'])->map(fn ($r) => $this->row([$r['code'], $r['name'], ucfirst(is_string($r['type']) ? $r['type'] : $r['type']->value), $r['debit'], $r['credit']]))->all();
        $rows[] = $this->row(['', 'Total', '', $tb['totals']['debit'], $tb['totals']['credit']], 'total');

        return $this->table('Trial balance', $this->asOfLabel($asOf), ['Code', 'Account', 'Type', 'Debit', 'Credit'], [3, 4], $rows,
            $tb['balanced'] ? [] : ['Debits and credits do not balance.']);
    }

    public function balanceSheet(?string $asOf): array
    {
        $bs = $this->reports->balanceSheet($asOf);
        $rows = [];
        foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities', 'equity' => 'Equity'] as $key => $label) {
            $rows = [...$rows, ...$this->section($label, $bs[$key])];
        }
        $rows[] = $this->row(['', 'Total liabilities and equity', $bs['total_liabilities_and_equity']], 'total');

        return $this->table('Balance sheet', $this->asOfLabel($asOf), ['Code', 'Account', 'Balance'], [2], $rows,
            $bs['balanced'] ? [] : ['Assets do not equal liabilities and equity.']);
    }

    public function incomeStatement(?string $from, ?string $to): array
    {
        $is = $this->reports->incomeStatement($from, $to);
        $rows = [...$this->section('Revenue', $is['revenue']), ...$this->section('Expenses', $is['expenses'])];
        $rows[] = $this->row(['', $is['net_income'] < 0 ? 'Net loss' : 'Net income', $is['net_income']], 'total');

        return $this->table('Income statement', $this->rangeLabel($from, $to), ['Code', 'Account', 'Amount'], [2], $rows);
    }

    public function vatSummary(int $year, int $quarter): array
    {
        $v = $this->vat->summary($year, $quarter);
        $buckets = [...$v['months'], $v['total']];
        $line = fn (string $label, callable $get, string $kind = 'row') => $this->row([$label, ...array_map($get, $buckets)], $kind);
        $section = fn (string $label) => $this->row([$label, ...array_fill(0, count($buckets), '')], 'section');

        $rows = [
            $section('Sales'),
            $line('Vatable sales', fn ($b) => $b['sales']['vatable']),
            $line('Zero-rated sales', fn ($b) => $b['sales']['zero_rated']),
            $line('Exempt sales', fn ($b) => $b['sales']['exempt']),
            $line('Total sales', fn ($b) => $b['sales']['total']),
            $line('Output VAT', fn ($b) => $b['output_vat'], 'total'),
            $section('Purchases'),
            $line('Vatable purchases', fn ($b) => $b['purchases']['vatable']),
            $line('Zero-rated purchases', fn ($b) => $b['purchases']['zero_rated']),
            $line('Exempt purchases', fn ($b) => $b['purchases']['exempt']),
            $line('Total purchases', fn ($b) => $b['purchases']['total']),
            $line('Input VAT', fn ($b) => $b['input_vat'], 'total'),
            $line('Net VAT payable (negative: excess input VAT)', fn ($b) => $b['net_vat_payable'], 'total'),
        ];
        $columns = ['', ...array_map(fn ($m) => CarbonImmutable::parse($m['month'].'-01')->format('M Y'), $v['months']), 'Quarter'];

        $notes = [];
        if ($v['other_vat_activity'] !== []) {
            $notes[] = sprintf('%d line(s) on VAT accounts in entries without a VAT code are not included (e.g. settlements): %s.',
                count($v['other_vat_activity']),
                implode(', ', array_unique(array_column($v['other_vat_activity'], 'number'))));
        }
        if ($v['vat_registered'] === false) {
            $notes[] = 'The company is not VAT-registered.';
        }

        $label = sprintf('Q%d %d (%s to %s), calendar quarter', $quarter, $year, $this->date($v['from']), $this->date($v['to']));

        return $this->table('VAT summary', $label, $columns, range(1, count($buckets)), $rows, $notes);
    }

    public function ledger(Account $account, ?string $from, ?string $to): array
    {
        $l = $this->reports->accountLedger($account, $from, $to);
        $rows = [$this->row(['', '', 'Opening balance', '', '', '', $l['opening_balance']], 'total')];
        foreach ($l['rows'] as $r) {
            $rows[] = $this->row([$r['entry_date'], $r['number'], $r['description'], (string) ($r['memo'] ?? ''), $r['debit'], $r['credit'], $r['balance']]);
        }
        $rows[] = $this->row(['', '', 'Closing balance', '', '', '', $l['closing_balance']], 'total');

        return $this->table("General ledger: {$account->code} {$account->name}", $this->rangeLabel($from, $to),
            ['Date', 'Entry', 'Description', 'Memo', 'Debit', 'Credit', 'Balance'], [4, 5, 6], $rows);
    }

    /** @param Builder<AuditLog> $query */
    public function auditLogs(Builder $query, ?string $from, ?string $to, int $limit): array
    {
        $rows = [];
        foreach ($query->with('user:id,name,email')->latest('id')->limit($limit)->cursor() as $log) {
            $rows[] = $this->row([
                $log->created_at?->format('Y-m-d H:i:s'), $log->user?->name ?? '', $log->user?->email ?? '', $log->action,
                (string) $log->auditable_type, (string) $log->auditable_id, (string) $log->ip_address,
                $log->old_values ? json_encode($log->old_values, JSON_UNESCAPED_UNICODE) : '',
                $log->new_values ? json_encode($log->new_values, JSON_UNESCAPED_UNICODE) : '',
            ]);
        }

        return $this->table('Audit trail', $this->rangeLabel($from, $to),
            ['Date and time', 'User', 'Email', 'Action', 'Record type', 'Record ID', 'IP address', 'Old values', 'New values'], [], $rows,
            count($rows) >= $limit ? ["Limited to the latest {$limit} rows; narrow the dates for more."] : []);
    }

    /** @return list<array> */
    private function section(string $label, array $section): array
    {
        $rows = [$this->row(['', $label, ''], 'section')];
        foreach ($section['rows'] as $r) {
            $rows[] = $this->row([$r['code'], $r['name'], $r['balance']]);
        }
        $rows[] = $this->row(['', "Total {$label}", $section['total']], 'total');

        return $rows;
    }

    private function row(array $cells, string $kind = 'row'): array
    {
        return ['cells' => $cells, 'kind' => $kind];
    }

    private function table(string $title, string $period, array $columns, array $money, array $rows, array $notes = []): array
    {
        return compact('title', 'period', 'columns', 'money', 'rows', 'notes');
    }

    private function asOfLabel(?string $asOf): string
    {
        return 'As of '.$this->date($asOf ?? now()->toDateString());
    }

    private function rangeLabel(?string $from, ?string $to): string
    {
        return match (true) {
            $from && $to => $this->date($from).' to '.$this->date($to),
            (bool) $from => 'From '.$this->date($from),
            (bool) $to => 'Up to '.$this->date($to),
            default => 'All dates',
        };
    }

    private function date(string $iso): string
    {
        return CarbonImmutable::parse($iso)->format('M j, Y');
    }
}
