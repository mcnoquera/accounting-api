<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\JournalEntryController;
use App\Http\Controllers\Api\V1\PeriodController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\TaxCodeController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::get('dashboard', [ReportController::class, 'dashboard']);

        Route::get('company', [CompanyController::class, 'show']);
        Route::put('company', [CompanyController::class, 'update']);

        Route::apiResource('users', UserController::class)->except(['destroy']);
        Route::post('users/{user}/unlock', [UserController::class, 'unlock']);

        Route::post('accounts/load-defaults', [AccountController::class, 'loadDefaults']);
        Route::apiResource('accounts', AccountController::class);
        Route::get('accounts/{account}/ledger', [AccountController::class, 'ledger']);

        Route::apiResource('tax-codes', TaxCodeController::class)->only(['index', 'store', 'update'])
            ->parameters(['tax-codes' => 'taxCode']);

        Route::apiResource('journal-entries', JournalEntryController::class)
            ->parameters(['journal-entries' => 'journalEntry']);
        Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post']);
        Route::post('journal-entries/{journalEntry}/void', [JournalEntryController::class, 'void']);
        Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse']);

        Route::get('periods', [PeriodController::class, 'index']);
        Route::post('periods/{period}/close', [PeriodController::class, 'close'])->where('period', '\d{4}-(0[1-9]|1[0-2])');
        Route::post('periods/{period}/reopen', [PeriodController::class, 'reopen'])->where('period', '\d{4}-(0[1-9]|1[0-2])');

        Route::prefix('reports')->group(function () {
            Route::get('trial-balance', [ReportController::class, 'trialBalance']);
            Route::get('balance-sheet', [ReportController::class, 'balanceSheet']);
            Route::get('income-statement', [ReportController::class, 'incomeStatement']);
        });

        Route::prefix('audit')->group(function () {
            Route::get('logs', [AuditController::class, 'logs']);
            Route::get('exceptions', [AuditController::class, 'exceptions']);
            Route::get('findings', [AuditController::class, 'findings']);
            Route::post('findings', [AuditController::class, 'raise']);
            Route::post('findings/{finding}/resolve', [AuditController::class, 'resolve']);
        });
    });
});
