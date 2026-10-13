<?php

namespace App\Repositories\Contracts;

use App\Models\JournalEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface JournalEntryRepositoryInterface
{
    /** @param array{status?: string, from?: string, to?: string, q?: string, account_id?: int} $filters */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator;

    public function findOrFail(int $id): JournalEntry;

    /** @param list<array{account_id:int, debit:int, credit:int, memo:?string}> $lines */
    public function create(array $attributes, array $lines): JournalEntry;

    /** @param list<array{account_id:int, debit:int, credit:int, memo:?string}> $lines */
    public function update(JournalEntry $entry, array $attributes, array $lines): JournalEntry;

    public function save(JournalEntry $entry): JournalEntry;

    public function delete(JournalEntry $entry): void;

    public function nextNumber(): string;

    /** Posted entries with lines, optionally within a date range. @return Collection<int, JournalEntry> */
    public function posted(?string $from = null, ?string $to = null): Collection;
}
