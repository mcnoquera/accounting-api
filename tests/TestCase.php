<?php

namespace Tests;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function actingAsRole(Role $role): User
    {
        $user = User::factory()->role($role)->create();
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, Account> keyed by type value */
    protected function basicAccounts(): array
    {
        $accounts = [];
        foreach (AccountType::cases() as $i => $type) {
            $accounts[$type->value] = Account::factory()->type($type)->create(['code' => (string) (1000 * ($i + 1))]);
        }

        return $accounts;
    }

    /** @param list<array{0: Account, 1: string|int, 2: string|int}> $lines */
    protected function entryPayload(array $lines, string $date = '2026-03-02', string $description = 'Test entry'): array
    {
        return [
            'entry_date' => $date,
            'description' => $description,
            'lines' => array_map(fn ($l) => ['account_id' => $l[0]->id, 'debit' => (string) $l[1], 'credit' => (string) $l[2]], $lines),
        ];
    }
}
