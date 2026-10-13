<?php

namespace App\Services\AuditRules;

use App\Enums\Severity;
use Illuminate\Support\Collection;

class LargeAmountRule implements AuditRule
{
    /** @param int $threshold in cents */
    public function __construct(private int $threshold) {}

    public function key(): string
    {
        return 'large_amount';
    }

    public function title(): string
    {
        return 'Amount above materiality threshold';
    }

    public function evaluate(Collection $entries): array
    {
        return $entries
            ->filter(fn ($e) => $e->totalDebit() >= $this->threshold)
            ->map(fn ($e) => new AuditFlag($this->key(), $this->title(), Severity::High, $e, sprintf(
                'Entry total %s meets or exceeds the %s threshold.',
                number_format($e->totalDebit() / 100, 2), number_format($this->threshold / 100, 2),
            )))
            ->values()->all();
    }
}
