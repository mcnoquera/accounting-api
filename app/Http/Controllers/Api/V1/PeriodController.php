<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReopenPeriodRequest;
use App\Services\PeriodService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PeriodController extends Controller
{
    public function __construct(private PeriodService $periods) {}

    public function index(): JsonResponse
    {
        $overview = $this->periods->overview();

        return ApiResponse::ok($overview['periods'], meta: array_diff_key($overview, ['periods' => true]));
    }

    /** Closing with drafts still dated in the month is allowed; draft_count lets the UI warn. */
    public function close(Request $request, string $period): JsonResponse
    {
        Gate::authorize('close-periods');
        $result = $this->periods->close($period, $request->user());

        return ApiResponse::ok($result['period'], meta: [
            'draft_count' => $result['draft_count'],
            'locked_through' => $this->periods->lockedThrough(),
        ]);
    }

    public function reopen(ReopenPeriodRequest $request, string $period): JsonResponse
    {
        Gate::authorize('reopen-periods');
        $row = $this->periods->reopen($period, $request->user(), $request->validated('reason'));

        return ApiResponse::ok($row, meta: ['locked_through' => $this->periods->lockedThrough()]);
    }
}
