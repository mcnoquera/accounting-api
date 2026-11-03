<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Voided = 'voided';

    /** Issued and not fully settled or voided. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Issued, self::PartiallyPaid], true);
    }
}
