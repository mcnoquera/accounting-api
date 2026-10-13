<?php

namespace App\Services\AuditRules;

use App\Enums\Severity;
use Illuminate\Support\Collection;

/** Large, perfectly round amounts are a classic indicator of estimates or manipulated entries. */
class RoundAmountRule implements AuditRule
{
    /** @param int $minimum in cents  @param int $multiple in cents */
    public function __construct(private int $minimum, private int $multiple) {}

    public function key(): string
    {
        return 'round_amount';
    }

    public function title(): string
    {
        return 'Large round-number amount';
    }

    public function evaluate(Collection $entries): array
    {
        return $entries
            ->filter(fn ($e) => $e->totalDebit() >= $this->minimum && $e->totalDebit() % $this->multiple === 0)
            ->map(fn ($e) => new AuditFlag($this->key(), $this->title(), Severity::Low, $e, sprintf(
                'Entry total %s is an exact multiple of %s.',
                number_format($e->totalDebit() / 100, 2), number_format($this->multiple / 100, 2),
            )))
            ->values()->all();
    }
}
