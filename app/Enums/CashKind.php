<?php

namespace App\Enums;

/** What a cash account holds; banks and e-wallets are reconciled separately. */
enum CashKind: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Ewallet = 'ewallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank',
            self::Ewallet => 'E-wallet',
        };
    }
}
