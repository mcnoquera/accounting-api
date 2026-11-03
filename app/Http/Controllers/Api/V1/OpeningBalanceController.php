<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OpeningBalanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OpeningBalanceController extends Controller
{
    public function __construct(private OpeningBalanceService $openings) {}

    public function show(): JsonResponse
    {
        return ApiResponse::ok($this->openings->current());
    }

    public function save(Request $request): JsonResponse
    {
        Gate::authorize('close-periods');
        $money = ['regex:/^\d{1,13}(\.\d{1,2})?$/'];
        $data = $request->validate([
            'cutover_date' => ['required', 'date_format:Y-m-d'],
            'equity_account_id' => ['nullable', 'integer'],
            'lines' => ['nullable', 'array', 'max:2000'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', ...$money],
            'lines.*.credit' => ['nullable', ...$money],
            'receivables' => ['nullable', 'array', 'max:5000'],
            'receivables.*.contact_id' => ['required', 'integer'],
            'receivables.*.number' => ['required', 'string', 'max:100'],
            'receivables.*.date' => ['required', 'date_format:Y-m-d'],
            'receivables.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'receivables.*.amount' => ['required', ...$money],
            'payables' => ['nullable', 'array', 'max:5000'],
            'payables.*.contact_id' => ['required', 'integer'],
            'payables.*.number' => ['required', 'string', 'max:100'],
            'payables.*.date' => ['required', 'date_format:Y-m-d'],
            'payables.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'payables.*.amount' => ['required', ...$money],
        ]);

        return ApiResponse::ok($this->openings->save($data, $request->user()));
    }
}
