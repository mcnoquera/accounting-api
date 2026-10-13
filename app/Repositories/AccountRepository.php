<?php

namespace App\Repositories;

use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use Illuminate\Support\Collection;

class AccountRepository implements AccountRepositoryInterface
{
    public function all(?string $type = null, ?bool $active = null): Collection
    {
        return Account::query()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($active !== null, fn ($q) => $q->where('is_active', $active))
            ->orderBy('code')
            ->get();
    }

    public function findOrFail(int $id): Account
    {
        return Account::findOrFail($id);
    }

    public function create(array $data): Account
    {
        return Account::create($data);
    }

    public function update(Account $account, array $data): Account
    {
        $account->update($data);

        return $account->refresh();
    }

    public function delete(Account $account): void
    {
        $account->delete();
    }

    public function hasLines(Account $account): bool
    {
        return $account->lines()->exists();
    }

    public function findMany(array $ids): Collection
    {
        return Account::whereIn('id', $ids)->get()->keyBy('id');
    }
}
