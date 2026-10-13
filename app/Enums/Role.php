<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Auditor = 'auditor';
}
