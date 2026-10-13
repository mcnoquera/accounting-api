<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use Illuminate\Support\Collection;

class AccountService
{
    public function __construct(private AccountRepositoryInterface $accounts) {}

    public function list(?string $type = null, ?bool $active = null): Collection
    {
        return $this->accounts->all($type, $active);
    }

    public function find(int $id): Account
    {
        return $this->accounts->findOrFail($id);
    }

    public function create(array $data): Account
    {
        return $this->accounts->create($data);
    }

    public function update(Account $account, array $data): Account
    {
        if (isset($data['type']) && $data['type'] !== $account->type->value && $this->accounts->hasLines($account)) {
            throw new DomainRuleException('Cannot change the type of an account that has journal activity.', [
                'type' => ['Account has journal lines; create a new account instead.'],
            ]);
        }

        return $this->accounts->update($account, $data);
    }

    public function delete(Account $account): void
    {
        if ($this->accounts->hasLines($account)) {
            throw new DomainRuleException('Cannot delete an account that has journal activity. Deactivate it instead.');
        }

        $this->accounts->delete($account);
    }
}
