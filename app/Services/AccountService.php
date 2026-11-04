<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Support\DefaultChartOfAccounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
        $this->assertValidParent(null, $data['parent_id'] ?? null, $data['type']);

        return $this->accounts->create($data);
    }

    public function update(Account $account, array $data): Account
    {
        $type = $data['type'] ?? $account->type->value;
        $typeChanges = $type !== $account->type->value;

        if ($typeChanges && $this->accounts->hasLines($account)) {
            throw new DomainRuleException('Cannot change the type of an account that has journal activity.', [
                'type' => ['Account has journal lines; create a new account instead.'],
            ]);
        }
        if ($typeChanges && $this->accounts->hasChildren($account)) {
            throw new DomainRuleException('Cannot change the type of a group account.', [
                'type' => ['Move or change its sub-accounts first.'],
            ]);
        }

        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $account->parent_id;
        $this->assertValidParent($account, $parentId, $type);

        return $this->accounts->update($account, $data);
    }

    public function delete(Account $account): void
    {
        if ($this->accounts->hasLines($account)) {
            throw new DomainRuleException('Cannot delete an account that has journal activity. Deactivate it instead.');
        }
        if ($this->accounts->hasChildren($account)) {
            throw new DomainRuleException('Cannot delete a group account that has sub-accounts.');
        }

        $this->accounts->delete($account);
    }

    /**
     * Loads the Philippine starter chart into an empty chart of accounts.
     *
     * @return Collection<int, Account>
     */
    public function loadDefaults(): Collection
    {
        return DB::transaction(function () {
            if (! $this->accounts->isEmpty()) {
                throw new DomainRuleException('The chart of accounts already has accounts. Defaults can only be loaded into an empty chart.');
            }

            $ids = [];
            foreach (DefaultChartOfAccounts::rows() as [$code, $name, $type, $parent]) {
                $ids[$code] = $this->accounts->create([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'parent_id' => $parent ? $ids[$parent] : null,
                    'tax_role' => DefaultChartOfAccounts::TAX_ROLES[$code] ?? null,
                    'control_type' => DefaultChartOfAccounts::CONTROL_TYPES[$code] ?? null,
                    'is_cash' => in_array($code, DefaultChartOfAccounts::CASH_ACCOUNTS, true),
                    'cash_kind' => DefaultChartOfAccounts::CASH_KINDS[$code][0] ?? null,
                    'provider' => DefaultChartOfAccounts::CASH_KINDS[$code][1] ?? null,
                ])->id;
            }

            return $this->accounts->all();
        });
    }

    /**
     * A parent must exist, share the account's type, have no journal lines (entries post to
     * detail accounts only) and must not be the account itself or one of its descendants.
     */
    private function assertValidParent(?Account $account, mixed $parentId, string $type): void
    {
        if ($parentId === null || $parentId === '') {
            return;
        }
        $parentId = (int) $parentId;

        $parent = $this->accounts->findOrFail($parentId);
        $fail = fn (string $msg) => throw new DomainRuleException($msg, ['parent_id' => [$msg]]);

        if ($parent->type->value !== $type) {
            $fail('The parent account must be the same type.');
        }
        if ($this->accounts->hasLines($parent)) {
            $fail('The parent account already has journal activity, so it cannot become a group account.');
        }

        for ($node = $parent, $depth = 0; $node && $account && $depth < 50; $node = $node->parent, $depth++) {
            if ($node->id === $account->id) {
                $fail('An account cannot be placed under itself or one of its sub-accounts.');
            }
        }
    }
}
