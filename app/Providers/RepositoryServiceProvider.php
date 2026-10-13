<?php

namespace App\Providers;

use App\Repositories\AccountRepository;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Repositories\Contracts\LedgerRepositoryInterface;
use App\Repositories\JournalEntryRepository;
use App\Repositories\LedgerRepository;
use App\Services\AuditExceptionService;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public array $bindings = [
        AccountRepositoryInterface::class => AccountRepository::class,
        JournalEntryRepositoryInterface::class => JournalEntryRepository::class,
        LedgerRepositoryInterface::class => LedgerRepository::class,
    ];

    public function register(): void
    {
        $this->app->bind(AuditExceptionService::class, function ($app) {
            $rules = collect(config('audit.rules', []))
                ->map(fn (array $params, string $class) => $app->make($class, $params))
                ->values();

            return new AuditExceptionService($app->make(JournalEntryRepositoryInterface::class), $rules);
        });
    }
}
