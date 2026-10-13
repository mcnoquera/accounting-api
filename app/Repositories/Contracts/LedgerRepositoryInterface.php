<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface LedgerRepositoryInterface
{
    /**
     * Posted debit/credit totals per account within an optional date range.
     *
     * @return Collection<int, object{account_id:int, code:string, name:string, type:string, debit:int, credit:int}>
     */
    public function accountTotals(?string $from = null, ?string $to = null): Collection;

    /**
     * Posted lines for one account, oldest first.
     *
     * @return Collection<int, object{entry_id:int, number:string, entry_date:string, description:string, memo:?string, debit:int, credit:int}>
     */
    public function accountLines(int $accountId, ?string $from = null, ?string $to = null): Collection;
}
