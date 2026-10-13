<?php

namespace App\Services\AuditRules;

use App\Enums\Severity;
use Illuminate\Support\Collection;

/** Same date, same amount, same accounts: possible double-recording. */
class DuplicateEntryRule implements AuditRule
{
    public function key(): string
    {
        return 'possible_duplicate';
    }

    public function title(): string
    {
        return 'Possible duplicate entry';
    }

    public function evaluate(Collection $entries): array
    {
        $fingerprint = fn ($e) => implode('|', [
            $e->entry_date->toDateString(),
            $e->totalDebit(),
            $e->lines->map(fn ($l) => "{$l->account_id}:{$l->debit}:{$l->credit}")->sort()->implode(','),
        ]);

        return $entries
            ->groupBy($fingerprint)
            ->filter(fn ($group) => $group->count() > 1)
            ->flatMap(fn ($group) => $group->map(fn ($e) => new AuditFlag($this->key(), $this->title(), Severity::Medium, $e,
                'Matches '.$group->where('id', '!=', $e->id)->pluck('number')->implode(', ').' on date, amount and accounts.')))
            ->values()->all();
    }
}
