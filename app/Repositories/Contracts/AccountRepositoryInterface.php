<?php

namespace App\Repositories\Contracts;

use App\Models\Account;
use Illuminate\Support\Collection;

interface AccountRepositoryInterface
{
    /** @return Collection<int, Account> */
    public function all(?string $type = null, ?bool $active = null): Collection;

    public function findOrFail(int $id): Account;

    public function create(array $data): Account;

    public function update(Account $account, array $data): Account;

    public function delete(Account $account): void;

    public function hasLines(Account $account): bool;

    /** @param list<int> $ids  @return Collection<int, Account> keyed by id */
    public function findMany(array $ids): Collection;
}
