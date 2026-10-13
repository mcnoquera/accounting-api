<?php

namespace App\Services\AuditRules;

use App\Enums\Severity;
use App\Models\JournalEntry;
use JsonSerializable;

final class AuditFlag implements JsonSerializable
{
    public function __construct(
        public readonly string $rule,
        public readonly string $title,
        public readonly Severity $severity,
        public readonly JournalEntry $entry,
        public readonly string $message,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'rule' => $this->rule,
            'title' => $this->title,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'entry' => [
                'id' => $this->entry->id,
                'number' => $this->entry->number,
                'entry_date' => $this->entry->entry_date->toDateString(),
                'description' => $this->entry->description,
                'amount' => $this->entry->totalDebit(),
            ],
        ];
    }
}
