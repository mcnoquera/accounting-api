<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeRequest;
use App\Services\DashboardService;
use App\Services\PercentageTaxService;
use App\Services\ReportService;
use App\Services\VatSummaryService;
use App\Services\WithholdingSummaryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function trialBalance(DateRangeRequest $request): JsonResponse
    {
        return ApiResponse::ok($this->reports->trialBalance($request->validated('as_of')));
    }

    public function balanceSheet(DateRangeRequest $request): JsonResponse
    {
        return ApiResponse::ok($this->reports->balanceSheet($request->validated('as_of')));
    }

    public function incomeStatement(DateRangeRequest $request): JsonResponse
    {
        return ApiResponse::ok($this->reports->incomeStatement($request->validated('from'), $request->validated('to')));
    }

    /** Calendar quarter; defaults to the current one. */
    public function vatSummary(Request $request, VatSummaryService $vat): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
        ]);

        return ApiResponse::ok($vat->summary(
            (int) ($data['year'] ?? now()->year),
            (int) ($data['quarter'] ?? now()->quarter),
        ));
    }

    /** Calendar quarter; defaults to the current one. */
    public function withholding(Request $request, WithholdingSummaryService $service): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
        ]);

        return ApiResponse::ok($service->summary(
            (int) ($data['year'] ?? now()->year),
            (int) ($data['quarter'] ?? now()->quarter),
        ));
    }

    /** Calendar quarter; defaults to the current one. */
    public function percentageTax(Request $request, PercentageTaxService $service): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
        ]);

        return ApiResponse::ok($service->summary((int) ($data['year'] ?? now()->year), (int) ($data['quarter'] ?? now()->quarter)));
    }

    public function dashboard(DashboardService $dashboard): JsonResponse
    {
        return ApiResponse::ok($dashboard->summary());
    }
}
