<?php

namespace App\Repositories;

use App\Enums\EntryStatus;
use App\Models\JournalEntry;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class JournalEntryRepository implements JournalEntryRepositoryInterface
{
    private const RELATIONS = ['lines.account', 'creator:id,name', 'poster:id,name', 'voider:id,name'];

    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return JournalEntry::query()
            ->with(self::RELATIONS)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->whereHas('lines', fn ($l) => $l->where('account_id', $id)))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(fn ($w) => $w->where('number', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('reference', 'like', "%{$term}%"));
            })
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findOrFail(int $id): JournalEntry
    {
        return JournalEntry::with([...self::RELATIONS, 'findings'])->findOrFail($id);
    }

    public function create(array $attributes, array $lines): JournalEntry
    {
        $entry = JournalEntry::create($attributes);
        $entry->lines()->createMany($lines);

        return $this->findOrFail($entry->id);
    }

    public function update(JournalEntry $entry, array $attributes, array $lines): JournalEntry
    {
        $entry->update($attributes);
        $entry->lines()->delete();
        $entry->lines()->createMany($lines);

        return $this->findOrFail($entry->id);
    }

    public function save(JournalEntry $entry): JournalEntry
    {
        $entry->save();

        return $this->findOrFail($entry->id);
    }

    public function delete(JournalEntry $entry): void
    {
        $entry->delete();
    }

    public function nextNumber(): string
    {
        $last = JournalEntry::max('id') ?? 0;

        return sprintf('JE-%06d', $last + 1);
    }

    public function posted(?string $from = null, ?string $to = null): Collection
    {
        return JournalEntry::query()
            ->with(['lines.account'])
            ->where('status', EntryStatus::Posted)
            ->when($from, fn ($q) => $q->whereDate('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('entry_date', '<=', $to))
            ->orderBy('entry_date')
            ->get();
    }
}
