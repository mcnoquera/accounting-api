<?php

namespace App\Enums;

/** What an account holds for tax purposes; tax amounts on entries must post to these. */
enum TaxRole: string
{
    case InputVat = 'input_vat';
    case OutputVat = 'output_vat';
    case EwtPayable = 'ewt_payable';        // tax we withheld from suppliers
    case CwtReceivable = 'cwt_receivable';  // tax customers withheld from us (Form 2307)

    public function label(): string
    {
        return match ($this) {
            self::InputVat => 'Input VAT',
            self::OutputVat => 'Output VAT',
            self::EwtPayable => 'Expanded withholding tax payable',
            self::CwtReceivable => 'Creditable withholding tax',
        };
    }
}
