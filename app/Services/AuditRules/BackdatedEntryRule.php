<?php

namespace App\Services\AuditRules;

use App\Enums\Severity;
use Illuminate\Support\Collection;

/** Entries posted long after their effective date can hide period-end manipulation. */
class BackdatedEntryRule implements AuditRule
{
    public function __construct(private int $days) {}

    public function key(): string
    {
        return 'backdated';
    }

    public function title(): string
    {
        return 'Posted long after entry date';
    }

    public function evaluate(Collection $entries): array
    {
        return $entries
            ->filter(fn ($e) => $e->posted_at && $e->entry_date->copy()->startOfDay()->diffInDays($e->posted_at->copy()->startOfDay(), false) > $this->days)
            ->map(fn ($e) => new AuditFlag($this->key(), $this->title(), Severity::Medium, $e, sprintf(
                'Posted %s, %d days after the entry date.',
                $e->posted_at->toDateString(), (int) $e->entry_date->copy()->startOfDay()->diffInDays($e->posted_at->copy()->startOfDay()),
            )))
            ->values()->all();
    }
}
