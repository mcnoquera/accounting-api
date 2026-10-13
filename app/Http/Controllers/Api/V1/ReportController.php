<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DateRangeRequest;
use App\Services\DashboardService;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

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

    public function dashboard(DashboardService $dashboard): JsonResponse
    {
        return ApiResponse::ok($dashboard->summary());
    }
}
