<?php

namespace Tests\Unit;

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\AuditRules\BackdatedEntryRule;
use App\Services\AuditRules\DuplicateEntryRule;
use App\Services\AuditRules\LargeAmountRule;
use App\Services\AuditRules\RoundAmountRule;
use App\Services\AuditRules\SelfApprovedEntryRule;
use App\Services\AuditRules\WeekendEntryRule;
use Tests\TestCase;

class AuditRulesTest extends TestCase
{
    private function entry(int $id, int $cents, string $date = '2026-03-04', int $createdBy = 1, ?int $postedBy = 2, ?string $postedAt = null): JournalEntry
    {
        $e = new JournalEntry([
            'number' => sprintf('JE-%06d', $id), 'entry_date' => $date, 'description' => 'x',
            'created_by' => $createdBy, 'posted_by' => $postedBy, 'posted_at' => $postedAt ?? $date.' 12:00:00',
        ]);
        $e->id = $id;
        $e->setRelation('lines', collect([
            new JournalLine(['account_id' => 1, 'debit' => $cents, 'credit' => 0]),
            new JournalLine(['account_id' => 2, 'debit' => 0, 'credit' => $cents]),
        ]));

        return $e;
    }

    public function test_large_amount(): void
    {
        $flags = (new LargeAmountRule(10_000))->evaluate(collect([$this->entry(1, 9_999), $this->entry(2, 10_000)]));
        $this->assertCount(1, $flags);
        $this->assertSame(2, $flags[0]->entry->id);
    }

    public function test_self_approved(): void
    {
        $flags = (new SelfApprovedEntryRule)->evaluate(collect([$this->entry(1, 100, createdBy: 5, postedBy: 5), $this->entry(2, 100)]));
        $this->assertSame([1], array_map(fn ($f) => $f->entry->id, $flags));
    }

    public function test_round_amount(): void
    {
        $rule = new RoundAmountRule(1_000_000, 100_000);
        $flags = $rule->evaluate(collect([$this->entry(1, 1_000_000), $this->entry(2, 1_000_001), $this->entry(3, 100_000)]));
        $this->assertSame([1], array_map(fn ($f) => $f->entry->id, $flags));
    }

    public function test_weekend(): void
    {
        $flags = (new WeekendEntryRule)->evaluate(collect([$this->entry(1, 1, '2026-03-07'), $this->entry(2, 1, '2026-03-09')]));
        $this->assertSame([1], array_map(fn ($f) => $f->entry->id, $flags));
    }

    public function test_backdated(): void
    {
        $flags = (new BackdatedEntryRule(30))->evaluate(collect([
            $this->entry(1, 1, '2026-01-01', postedAt: '2026-01-31 09:00:00'),
            $this->entry(2, 1, '2026-01-01', postedAt: '2026-02-01 09:00:00'),
        ]));
        $this->assertSame([2], array_map(fn ($f) => $f->entry->id, $flags));
    }

    public function test_duplicates(): void
    {
        $flags = (new DuplicateEntryRule)->evaluate(collect([$this->entry(1, 500), $this->entry(2, 500), $this->entry(3, 501)]));
        $this->assertEqualsCanonicalizing([1, 2], array_map(fn ($f) => $f->entry->id, $flags));
    }
}
