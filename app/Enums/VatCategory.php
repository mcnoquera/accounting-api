<?php

namespace App\Enums;

enum VatCategory: string
{
    case Vatable = 'vatable';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';
}
