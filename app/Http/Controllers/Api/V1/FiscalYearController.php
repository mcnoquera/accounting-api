<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\YearEndService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FiscalYearController extends Controller
{
    public function __construct(private YearEndService $yearEnd) {}

    public function index(): JsonResponse
    {
        return ApiResponse::ok($this->yearEnd->list());
    }

    public function close(Request $request, int $year): JsonResponse
    {
        Gate::authorize('close-periods');
        $data = $request->validate(['retained_earnings_account_id' => ['required', 'integer']]);

        return ApiResponse::ok($this->yearEnd->close($year, (int) $data['retained_earnings_account_id'], $request->user()));
    }
}
