<?php

namespace App\Enums;

enum ContactType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Both = 'both';

    public function isCustomer(): bool
    {
        return $this !== self::Supplier;
    }

    public function isSupplier(): bool
    {
        return $this !== self::Customer;
    }
}
