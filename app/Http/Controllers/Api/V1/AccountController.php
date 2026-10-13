<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AccountRequest;
use App\Http\Requests\DateRangeRequest;
use App\Models\Account;
use App\Services\AccountService;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $active = $request->has('active') ? $request->boolean('active') : null;

        return ApiResponse::ok($this->accounts->list($request->query('type'), $active));
    }

    public function store(AccountRequest $request): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->accounts->create($request->validated()), 201);
    }

    public function show(Account $account): JsonResponse
    {
        return ApiResponse::ok($account);
    }

    public function update(AccountRequest $request, Account $account): JsonResponse
    {
        Gate::authorize('manage-books');

        return ApiResponse::ok($this->accounts->update($account, $request->validated()));
    }

    public function destroy(Account $account): JsonResponse
    {
        Gate::authorize('manage-books');
        $this->accounts->delete($account);

        return ApiResponse::ok(null);
    }

    public function ledger(DateRangeRequest $request, Account $account, ReportService $reports): JsonResponse
    {
        return ApiResponse::ok($reports->accountLedger($account, $request->validated('from'), $request->validated('to')));
    }
}
