<?php

namespace App\Services\AuditRules;

use App\Enums\Severity;
use Illuminate\Support\Collection;

/** Segregation of duties: the person who prepared an entry should not also post it. */
class SelfApprovedEntryRule implements AuditRule
{
    public function key(): string
    {
        return 'self_approved';
    }

    public function title(): string
    {
        return 'Prepared and posted by the same user';
    }

    public function evaluate(Collection $entries): array
    {
        return $entries
            ->filter(fn ($e) => $e->posted_by !== null && $e->posted_by === $e->created_by)
            ->map(fn ($e) => new AuditFlag($this->key(), $this->title(), Severity::Medium, $e,
                'Entry was created and posted by the same user, bypassing review.'))
            ->values()->all();
    }
}
