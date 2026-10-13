<?php

namespace App\Services\AuditRules;

use App\Models\JournalEntry;
use Illuminate\Support\Collection;

/**
 * A check run over posted journal entries that flags items an auditor should look at.
 * Add a new rule by implementing this interface and registering it in config/audit.php.
 */
interface AuditRule
{
    public function key(): string;

    public function title(): string;

    /**
     * @param  Collection<int, JournalEntry>  $entries  posted entries with lines loaded
     * @return list<AuditFlag>
     */
    public function evaluate(Collection $entries): array;
}
