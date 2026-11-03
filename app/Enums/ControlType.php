<?php

namespace App\Enums;

/** A subsidiary-ledger control account: every line on it must name a contact. */
enum ControlType: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';

    public function accepts(ContactType $type): bool
    {
        return $this === self::Receivable ? $type->isCustomer() : $type->isSupplier();
    }

    public function contactLabel(): string
    {
        return $this === self::Receivable ? 'customer' : 'supplier';
    }

    /** Balance in the account's normal direction: receivables are debits, payables credits. */
    public function balance(int $debit, int $credit): int
    {
        return $this === self::Receivable ? $debit - $credit : $credit - $debit;
    }
}
