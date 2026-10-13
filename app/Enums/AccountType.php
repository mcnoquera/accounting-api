<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /** Assets and expenses increase with debits; the rest increase with credits. */
    public function isDebitNormal(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }

    /** Signed balance in the account's normal direction. */
    public function balance(int $debit, int $credit): int
    {
        return $this->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }
}
