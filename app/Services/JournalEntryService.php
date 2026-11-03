<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\EntryStatus;
use App\Enums\TaxKind;
use App\Enums\TaxRole;
use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\User;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JournalEntryService
{
    public function __construct(
        private JournalEntryRepositoryInterface $entries,
        private AccountRepositoryInterface $accounts,
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
        $lines = $entry->lines->map(fn ($l) => [
            'account_id' => $l->account_id, 'debit' => $l->debit, 'credit' => $l->credit, 'memo' => $l->memo,
            'vat_code_id' => $l->vat_code_id, 'ewt_code_id' => $l->ewt_code_id,
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

        $entry->status = EntryStatus::Voided;
        $entry->voided_by = $voider->id;
        $entry->voided_at = now();
        $entry->void_reason = $reason;

        return $this->entries->save($entry);
    }

    /**
     * Cancels a posted entry with a new, posted entry that swaps every debit and credit,
     * dated $date (an open period). The original stays posted, so both appear in the
     * ledger and net to zero; each entry links to the other.
     */
    public function reverse(JournalEntry $entry, User $user, string $date, string $reason): JournalEntry
    {
        return DB::transaction(function () use ($entry, $user, $date, $reason) {
            $entry = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);

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

            $lines = $entry->lines->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => $l->credit,
                'credit' => $l->debit,
                'memo' => $l->memo,
                'vat_code_id' => $l->vat_code_id,
                'ewt_code_id' => $l->ewt_code_id,
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
     * @return list<array{account_id:int, debit:int, credit:int, memo:?string, vat_code_id:?int, ewt_code_id:?int}>
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
                'vat_code_id' => self::optionalId($line['vat_code_id'] ?? null),
                'ewt_code_id' => self::optionalId($line['ewt_code_id'] ?? null),
            ];
        }

        return $normalized;
    }

    private static function optionalId(mixed $id): ?int
    {
        return $id === null || $id === '' ? null : (int) $id;
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

    /** @param list<array{account_id:int, debit:int, credit:int, memo:?string, vat_code_id?:?int, ewt_code_id?:?int}> $lines */
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

        $this->assertTaxValid($lines, $found);
    }

    /** Allowed difference per tax account role, for rounding line by line. */
    private const TAX_TOLERANCE = 100;

    /**
     * A tax code tags a base line (the sale or purchase); its amount is the tax base. Every
     * tax account role touched by a non-zero rate must carry base × rate (±₱1) on the right
     * side: VAT on the base line's side, withholding on the other. Revenue lines are sales,
     * everything else purchases, so returns and reversals check out the same way.
     *
     * @param  list<array{account_id:int, debit:int, credit:int, vat_code_id?:?int, ewt_code_id?:?int}>  $lines
     * @param  Collection<int, Account>  $accounts  keyed by id
     */
    private function assertTaxValid(array $lines, Collection $accounts): void
    {
        $codeIds = array_values(array_unique(array_filter([...array_column($lines, 'vat_code_id'), ...array_column($lines, 'ewt_code_id')])));
        if ($codeIds === []) {
            return;
        }
        $codes = TaxCode::query()->findMany($codeIds)->keyBy('id');

        $expected = []; // role => signed amount (debit +, credit -)
        foreach ($lines as $i => $line) {
            foreach (['vat_code_id', 'ewt_code_id'] as $field) {
                $code = isset($line[$field]) ? $codes->get($line[$field]) : null;
                if ($code) {
                    $this->addExpectedTax($expected, $code, $line, $accounts->get($line['account_id']), $i);
                }
            }
        }

        $this->assertTaxMatches($expected, $lines, $accounts);
    }

    /**
     * Adds the tax a coded base line implies to $expected (role => signed cents, debit +).
     *
     * @param  array<string, int>  $expected
     */
    private function addExpectedTax(array &$expected, TaxCode $code, array $line, Account $account, int $i): void
    {
        if (! $code->is_active) {
            throw new DomainRuleException("Tax code {$code->code} is inactive.", ["lines.$i" => ['Choose an active tax code.']]);
        }
        if ($account->tax_role !== null) {
            throw new DomainRuleException("Put tax code {$code->code} on the sale or purchase line, not on the tax account {$account->code}.", [
                "lines.$i" => ['Tax codes go on the base line; this account holds the tax itself.'],
            ]);
        }

        $isSale = $account->type === AccountType::Revenue;
        if ($isSale && $code->kind === TaxKind::Vat && $code->rate_bp > 0 && Company::current()?->vat_registered === false) {
            throw new DomainRuleException("The company isn't VAT-registered, so it can't charge output VAT ({$code->code}).", [
                "lines.$i" => ['Use VAT-exempt for sales of a non-VAT business.'],
            ]);
        }
        if ($code->rate_bp === 0) {
            return;
        }

        $baseIsDebit = $line['debit'] > 0;
        $taxIsDebit = $code->kind->sameSideAsBase() ? $baseIsDebit : ! $baseIsDebit;
        $tax = $code->taxOn($baseIsDebit ? $line['debit'] : $line['credit']);
        $role = $code->kind->role($isSale)->value;
        $expected[$role] = ($expected[$role] ?? 0) + ($taxIsDebit ? $tax : -$tax);
    }

    /**
     * @param  array<string, int>  $expected
     * @param  Collection<int, Account>  $accounts
     */
    private function assertTaxMatches(array $expected, array $lines, Collection $accounts): void
    {
        foreach ($expected as $role => $amount) {
            $role = TaxRole::from($role);
            $roleAccounts = $accounts->filter(fn ($a) => $a->tax_role === $role)->keys()->all();
            $actual = 0;
            foreach ($lines as $line) {
                if (in_array($line['account_id'], $roleAccounts, true)) {
                    $actual += $line['debit'] - $line['credit'];
                }
            }

            if (abs($actual - $amount) > self::TAX_TOLERANCE) {
                $side = $amount >= 0 ? 'debit' : 'credit';
                throw new DomainRuleException(sprintf(
                    '%s on this entry should be a %s of %s (from the tax codes), but the entry has %s.',
                    $role->label(), $side, number_format(abs($amount) / 100, 2),
                    $actual === 0 ? 'none' : number_format(abs($actual) / 100, 2).' '.($actual > 0 ? 'debit' : 'credit'),
                ), ['lines' => ["Add a {$side} line to an account tagged \"{$role->label()}\" for ".number_format(abs($amount) / 100, 2).'.']]);
            }
        }
    }

    private function assertStatus(JournalEntry $entry, EntryStatus $expected, string $message): void
    {
        if ($entry->status !== $expected) {
            throw new DomainRuleException($message);
        }
    }
}
