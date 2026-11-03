<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Every authenticated user can read the books. Writes are split by role so that
        // auditors stay independent of the people who record transactions.
        Gate::define('manage-users', fn (User $u) => $u->hasRole(Role::Admin));
        Gate::define('manage-company', fn (User $u) => $u->hasRole(Role::Admin));
        Gate::define('manage-tax-codes', fn (User $u) => $u->hasRole(Role::Admin));
        Gate::define('manage-books', fn (User $u) => $u->hasRole(Role::Admin, Role::Accountant));
        Gate::define('close-periods', fn (User $u) => $u->hasRole(Role::Admin, Role::Accountant));
        Gate::define('reopen-periods', fn (User $u) => $u->hasRole(Role::Admin));
        Gate::define('view-audit', fn (User $u) => $u->hasRole(Role::Admin, Role::Auditor));
        Gate::define('manage-findings', fn (User $u) => $u->hasRole(Role::Admin, Role::Auditor));
        Gate::define('resolve-findings', fn (User $u) => $u->hasRole(Role::Admin, Role::Auditor, Role::Accountant));
    }
}
