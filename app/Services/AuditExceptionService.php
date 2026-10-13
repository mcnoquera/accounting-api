<?php

namespace App\Services;

use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\AuditRules\AuditFlag;
use App\Services\AuditRules\AuditRule;

class AuditExceptionService
{
    /** @param iterable<AuditRule> $rules */
    public function __construct(
        private JournalEntryRepositoryInterface $entries,
        private iterable $rules,
    ) {}

    /** @return array{rules: list<array{key:string,title:string,count:int}>, flags: list<AuditFlag>} */
    public function run(?string $from = null, ?string $to = null): array
    {
        $posted = $this->entries->posted($from, $to);
        $summary = [];
        $flags = [];

        foreach ($this->rules as $rule) {
            $found = $rule->evaluate($posted);
            $summary[] = ['key' => $rule->key(), 'title' => $rule->title(), 'count' => count($found)];
            array_push($flags, ...$found);
        }

        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($flags, fn (AuditFlag $a, AuditFlag $b) => [$rank[$a->severity->value], $b->entry->entry_date] <=> [$rank[$b->severity->value], $a->entry->entry_date]);

        return ['rules' => $summary, 'flags' => $flags];
    }
}
