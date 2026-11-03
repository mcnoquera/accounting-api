<?php

namespace App\Enums;

enum TaxKind: string
{
    case Vat = 'vat';
    case Ewt = 'ewt';

    /**
     * Where the tax for a base line goes. VAT follows the base line's side (a sale credits
     * Output VAT, a purchase debits Input VAT, returns do the opposite); withholding goes
     * on the other side (we owe what we withheld; a customer's withholding is a credit to us).
     */
    public function role(bool $isSale): TaxRole
    {
        return match ($this) {
            self::Vat => $isSale ? TaxRole::OutputVat : TaxRole::InputVat,
            self::Ewt => $isSale ? TaxRole::CwtReceivable : TaxRole::EwtPayable,
        };
    }

    public function sameSideAsBase(): bool
    {
        return $this === self::Vat;
    }
}
