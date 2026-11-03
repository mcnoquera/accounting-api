<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ControlType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Services\Exports\Exporter;
use App\Services\Exports\ReportTables;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * File exports of reports, using the same filters as the JSON endpoints. Every export is
 * written to the audit trail.
 */
class ExportController extends Controller
{
    /** Audit log exports are capped to keep requests bounded. */
    private const AUDIT_LOG_LIMIT = 50_000;

    public function __construct(private ReportTables $tables, private Exporter $exporter) {}

    public function report(Request $request, string $report): Response
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(['csv', 'pdf'])],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'type' => [Rule::requiredIf($report === 'aging'), 'nullable', Rule::enum(ControlType::class)],
        ]);

        $table = match ($report) {
            'trial-balance' => $this->tables->trialBalance($data['as_of'] ?? null),
            'balance-sheet' => $this->tables->balanceSheet($data['as_of'] ?? null),
            'income-statement' => $this->tables->incomeStatement($data['from'] ?? null, $data['to'] ?? null),
            'aging' => $this->tables->aging(ControlType::from($data['type']), $data['as_of'] ?? null),
            'withholding' => $this->tables->withholding((int) ($data['year'] ?? now()->year), (int) ($data['quarter'] ?? now()->quarter)),
            'withholding-payees' => $this->tables->withholdingPayees((int) ($data['year'] ?? now()->year), (int) ($data['quarter'] ?? now()->quarter)),
            'percentage-tax' => $this->tables->percentageTax((int) ($data['year'] ?? now()->year), (int) ($data['quarter'] ?? now()->quarter)),
            'vat-summary' => $this->tables->vatSummary((int) ($data['year'] ?? now()->year), (int) ($data['quarter'] ?? now()->quarter)),
        };

        $slug = $report === 'aging' ? "{$data['type']}-aging" : $report;

        return $this->exporter->download($table, $data['format'], $slug, $data, $request->user());
    }

    public function ledger(Request $request, Account $account): Response
    {
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['csv'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $table = $this->tables->ledger($account, $data['from'] ?? null, $data['to'] ?? null);

        return $this->exporter->download($table, 'csv', "ledger-{$account->code}", $data + ['account_id' => $account->id], $request->user());
    }

    public function auditLogs(Request $request): Response
    {
        Gate::authorize('view-audit');
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['csv'])],
            'action' => ['nullable', 'string', 'max:30'],
            'type' => ['nullable', 'string', 'max:50'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $query = AuditLog::query()
            ->when($data['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($data['type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        $table = $this->tables->auditLogs($query, $data['from'] ?? null, $data['to'] ?? null, self::AUDIT_LOG_LIMIT);

        return $this->exporter->download($table, 'csv', 'audit-trail', $data, $request->user());
    }
}
