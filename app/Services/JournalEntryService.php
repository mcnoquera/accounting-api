<?php

namespace App\Services;

use App\Enums\EntryStatus;
use App\Exceptions\DomainRuleException;
use App\Models\JournalEntry;
use App\Models\User;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    public function __construct(
        private JournalEntryRepositoryInterface $entries,
        private AccountRepositoryInterface $accounts,
        private PeriodService $periods,
    ) {}

    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->entries->paginate($filters, $perPage);
    }

    public function find(int $id): JournalEntry
    {
        return $this->entries->findOrFail($id);
    }

    /** @param array{entry_date:string, reference?:?string, description:string, lines:list<array>} $data */
    public function createDraft(array $data, User $author): JournalEntry
    {
        $this->periods->assertOpen($data['entry_date'], 'The entry date is in a closed period ({period}).');
        $lines = $this->normalizeLines($data['lines']);
        $this->assertLinesValid($lines);

        return DB::transaction(fn () => $this->entries->create([
            'number' => $this->entries->nextNumber(),
            'entry_date' => $data['entry_date'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'],
            'status' => EntryStatus::Draft,
            'created_by' => $author->id,
        ], $lines));
    }

    public function updateDraft(JournalEntry $entry, array $data): JournalEntry
    {
        $this->assertStatus($entry, EntryStatus::Draft, 'Only draft entries can be edited.');
        // A draft left in a closed month may be moved to an open date, but nothing can be dated into one.
        $this->periods->assertOpen($data['entry_date'], 'The entry date is in a closed period ({period}).');
        $lines = $this->normalizeLines($data['lines']);
        $this->assertLinesValid($lines);

        return DB::transaction(fn () => $this->entries->update($entry, [
            'entry_date' => $data['entry_date'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'],
        ], $lines));
    }

    public function deleteDraft(JournalEntry $entry): void
    {
        $this->assertStatus($entry, EntryStatus::Draft, 'Only draft entries can be deleted. Void posted entries instead.');
        $this->entries->delete($entry);
    }

    public function post(JournalEntry $entry, User $poster): JournalEntry
    {
        $this->assertStatus($entry, EntryStatus::Draft, 'Only draft entries can be posted.');
        $this->periods->assertOpen(
            $entry->entry_date->format('Y-m-d'),
            'This entry is dated in a closed period ({period}). Change its date to an open period before posting.',
        );
        $lines = $entry->lines->map(fn ($l) => [
            'account_id' => $l->account_id, 'debit' => $l->debit, 'credit' => $l->credit, 'memo' => $l->memo,
        ])->all();
        $this->assertLinesValid($lines);

        $inactive = $entry->lines->filter(fn ($l) => ! $l->account->is_active);
        if ($inactive->isNotEmpty()) {
            throw new DomainRuleException('Entry uses inactive accounts: '.$inactive->pluck('account.code')->unique()->implode(', '));
        }

        $entry->status = EntryStatus::Posted;
        $entry->posted_by = $poster->id;
        $entry->posted_at = now();

        return $this->entries->save($entry);
    }

    public function void(JournalEntry $entry, User $voider, string $reason): JournalEntry
    {
        $this->assertStatus($entry, EntryStatus::Posted, 'Only posted entries can be voided.');
        if ($entry->reversal_of_id !== null || $entry->reversedBy()->exists()) {
            throw new DomainRuleException('This entry is part of a reversal and cannot be voided. The reversal already cancels its effect.');
        }
        $this->periods->assertOpen(
            $entry->entry_date->format('Y-m-d'),
            'This entry is in a closed period ({period}) and cannot be voided. Reverse it instead, dated in an open period.',
        );

        $entry->status = EntryStatus::Voided;
        $entry->voided_by = $voider->id;
        $entry->voided_at = now();
        $entry->void_reason = $reason;

        return $this->entries->save($entry);
    }

    /**
     * Cancels a posted entry with a new, posted entry that swaps every debit and credit,
     * dated $date (an open period). The original stays posted, so both appear in the
     * ledger and net to zero; each entry links to the other. Without a date it uses today,
     * or the first open date if today is in a closed period.
     */
    public function reverse(JournalEntry $entry, User $user, ?string $date, string $reason): JournalEntry
    {
        return DB::transaction(function () use ($entry, $user, $date, $reason) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $date ??= max(array_filter([now()->toDateString(), $this->periods->firstOpenDate(), $entry->entry_date->format('Y-m-d')]));

            $this->assertStatus($entry, EntryStatus::Posted, 'Only posted entries can be reversed.');
            if ($entry->reversal_of_id !== null) {
                throw new DomainRuleException('A reversing entry cannot itself be reversed.');
            }
            if ($entry->reversedBy()->exists()) {
                throw new DomainRuleException("Entry {$entry->number} has already been reversed.");
            }
            if ($date < $entry->entry_date->format('Y-m-d')) {
                throw new DomainRuleException('The reversal date cannot be before the original entry date.', [
                    'entry_date' => ['Use a date on or after '.$entry->entry_date->format('Y-m-d').'.'],
                ]);
            }
            $this->periods->assertOpen($date, 'The reversal date is in a closed period ({period}).');

            $lines = $entry->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => $l->credit,
                'credit' => $l->debit,
                'memo' => $l->memo,
            ])->all();

            return $this->entries->create([
                'number' => $this->entries->nextNumber(),
                'entry_date' => $date,
                'reference' => $entry->number,
                'description' => "Reversal of {$entry->number}: {$reason}",
                'status' => EntryStatus::Posted,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'posted_at' => now(),
                'reversal_of_id' => $entry->id,
            ], $lines);
        });
    }

    /**
     * Converts decimal amounts (e.g. "1250.50") into integer cents and drops empty lines.
     *
     * @return list<array{account_id:int, debit:int, credit:int, memo:?string}>
     */
    public function normalizeLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            $debit = self::toCents($line['debit'] ?? 0);
            $credit = self::toCents($line['credit'] ?? 0);
            if ($debit === 0 && $credit === 0) {
                continue;
            }
            $normalized[] = [
                'account_id' => (int) $line['account_id'],
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line['memo'] ?? null,
            ];
        }

        return $normalized;
    }

    /** Parses a decimal amount ("1250.5", 1250.50, "0") into integer cents without float math. */
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        $str = is_float($amount) ? number_format($amount, 2, '.', '') : trim((string) $amount);
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $str, $m)) {
            throw new DomainRuleException("Invalid amount: {$str}", ['lines' => ['Amounts must be positive numbers with at most 2 decimals.']]);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    /** @param list<array{account_id:int, debit:int, credit:int, memo:?string}> $lines */
    private function assertLinesValid(array $lines): void
    {
        if (count($lines) < 2) {
            throw new DomainRuleException('A journal entry needs at least two non-zero lines.', [
                'lines' => ['At least two lines with an amount are required.'],
            ]);
        }

        foreach ($lines as $i => $line) {
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                throw new DomainRuleException('A line cannot have both a debit and a credit.', [
                    "lines.$i" => ['Enter either a debit or a credit, not both.'],
                ]);
            }
        }

        $debits = array_sum(array_column($lines, 'debit'));
        $credits = array_sum(array_column($lines, 'credit'));
        if ($debits !== $credits) {
            throw new DomainRuleException(sprintf(
                'Entry is out of balance: debits %s, credits %s.',
                number_format($debits / 100, 2), number_format($credits / 100, 2),
            ), ['lines' => ['Total debits must equal total credits.']]);
        }

        $ids = array_unique(array_column($lines, 'account_id'));
        $found = $this->accounts->findMany($ids);
        $missing = array_diff($ids, $found->keys()->all());
        if ($missing !== []) {
            throw new DomainRuleException('Unknown account(s) on entry.', ['lines' => ['One or more accounts do not exist.']]);
        }

        $groups = $this->accounts->withChildren(array_values($ids));
        if ($groups !== []) {
            $codes = $found->only($groups)->pluck('code')->implode(', ');
            throw new DomainRuleException("Group accounts can't be used on entries: {$codes}.", [
                'lines' => ['Choose a detail account instead of a group account.'],
            ]);
        }
    }

    private function assertStatus(JournalEntry $entry, EntryStatus $expected, string $message): void
    {
        if ($entry->status !== $expected) {
            throw new DomainRuleException($message);
        }
    }
}
