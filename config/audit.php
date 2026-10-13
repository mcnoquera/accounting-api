<?php

use App\Services\AuditRules;

return [
    /*
    | Automated audit checks run by GET /api/v1/audit/exceptions.
    | Amounts are in cents.
    */
    'rules' => [
        AuditRules\LargeAmountRule::class => ['threshold' => (int) env('AUDIT_MATERIALITY_CENTS', 5_000_000)],
        AuditRules\SelfApprovedEntryRule::class => [],
        AuditRules\BackdatedEntryRule::class => ['days' => (int) env('AUDIT_BACKDATE_DAYS', 30)],
        AuditRules\DuplicateEntryRule::class => [],
        AuditRules\RoundAmountRule::class => ['minimum' => 1_000_000, 'multiple' => 100_000],
        AuditRules\WeekendEntryRule::class => [],
    ],
];
