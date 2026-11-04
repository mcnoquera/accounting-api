<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TransferService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TransferController extends Controller
{
    public function store(Request $request, TransferService $transfers): JsonResponse
    {
        Gate::authorize('manage-books');
        $money = ['regex:/^\d{1,13}(\.\d{1,2})?$/'];
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'from_account_id' => ['required', 'integer'],
            'to_account_id' => ['required', 'integer'],
            'amount' => ['required', ...$money],
            'fee' => ['nullable', ...$money],
            'fee_account_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::ok($transfers->record($data, $request->user()), 201);
    }
}
