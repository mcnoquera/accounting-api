<?php

namespace App\Services\AuditRules;

use App\Enums\Severity;
use Illuminate\Support\Collection;

class WeekendEntryRule implements AuditRule
{
    public function key(): string
    {
        return 'weekend';
    }

    public function title(): string
    {
        return 'Dated on a weekend';
    }

    public function evaluate(Collection $entries): array
    {
        return $entries
            ->filter(fn ($e) => $e->entry_date->isWeekend())
            ->map(fn ($e) => new AuditFlag($this->key(), $this->title(), Severity::Low, $e,
                "Entry is dated {$e->entry_date->format('l, Y-m-d')}."))
            ->values()->all();
    }
}
