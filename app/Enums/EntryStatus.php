<?php

namespace App\Enums;

enum EntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Voided = 'voided';
}
