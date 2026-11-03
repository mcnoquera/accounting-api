<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\ControlType;
use App\Enums\InvoiceStatus;
use App\Enums\TaxKind;
use App\Enums\TaxRole;
use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\PurchaseBill;
use App\Models\TaxCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Supplier bills. Recording one numbers it (PB-000001) and posts a journal entry through
 * JournalEntryService: Dr each expense/asset line (with its VAT and withholding codes),
 * Dr input VAT, Cr expanded withholding tax payable (withheld when the bill is recorded),
 * Cr payable (supplier) for what we owe them. Voiding an unpaid bill reverses the entry.
 */
class PurchaseBillService
{
    public const SOURCE = 'purchase_bill';

    public function __construct(private JournalEntryService $journal, private PeriodService $periods) {}

    public function saveDraft(array $data, User $user, ?PurchaseBill $bill = null): PurchaseBill
    {
        if ($bill && $bill->status !== InvoiceStatus::Draft) {
            throw new DomainRuleException('Only draft bills can be edited.');
        }
        $supplier = Contact::query()->findOrFail($data['contact_id']);
        if (! $supplier->type->isSupplier() || ! $supplier->is_active) {
            throw new DomainRuleException("{$supplier->name} isn't an active supplier.", ['contact_id' => ['Choose an active supplier.']]);
        }
        $this->assertUniqueSupplierInvoice($supplier->id, $data['supplier_invoice_number'], $bill?->id);
        $lines = $this->buildLines($data['lines']);
        [$vat, $ewt] = $this->taxesFor($lines);
        $net = array_sum(array_column($lines, 'amount'));

        return DB::transaction(function () use ($data, $user, $bill, $supplier, $lines, $net, $vat, $ewt) {
            $attributes = [
                'contact_id' => $supplier->id,
                'supplier_invoice_number' => trim($data['supplier_invoice_number']),
                'bill_date' => $data['bill_date'],
                'due_date' => $data['due_date'] ?? CarbonImmutable::parse($data['bill_date'])->addDays($supplier->payment_terms_days)->toDateString(),
                'notes' => $data['notes'] ?? null,
                'net_amount' => $net,
                'vat_amount' => $vat,
                'ewt_amount' => $ewt,
                'total_amount' => $net + $vat,
                'amount_due' => $net + $vat - $ewt,
            ];
            if ($bill) {
                $bill->update($attributes);
                $bill->lines()->delete();
            } else {
                $bill = PurchaseBill::create($attributes + ['status' => InvoiceStatus::Draft, 'created_by' => $user->id]);
            }
            $bill->lines()->createMany($lines);

            return $this->load($bill);
        });
    }

    public function delete(PurchaseBill $bill): void
    {
        if ($bill->status !== InvoiceStatus::Draft) {
            throw new DomainRuleException('Only draft bills can be deleted. Void a recorded bill instead.');
        }
        $bill->delete();
    }

    public function record(PurchaseBill $bill, User $user): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $user) {
            $bill = PurchaseBill::query()->lockForUpdate()->with('lines')->findOrFail($bill->id);
            if ($bill->status !== InvoiceStatus::Draft) {
                throw new DomainRuleException('Only draft bills can be recorded.');
            }
            if ($bill->amount_due <= 0) {
                throw new DomainRuleException('A bill needs an amount due above zero.');
            }
            $this->assertUniqueSupplierInvoice($bill->contact_id, $bill->supplier_invoice_number, $bill->id);

            $lines = [];
            foreach ($bill->lines->groupBy(fn ($l) => $l->account_id.'|'.$l->vat_code_id.'|'.$l->ewt_code_id) as $group) {
                $first = $group->first();
                $lines[] = [
                    'account_id' => $first->account_id, 'debit' => self::pesos($group->sum('amount')), 'credit' => '0',
                    'vat_code_id' => $first->vat_code_id, 'ewt_code_id' => $first->ewt_code_id, 'contact_id' => $bill->contact_id, 'memo' => null,
                ];
            }
            if ($bill->vat_amount > 0) {
                $lines[] = ['account_id' => $this->firstAccount(TaxRole::InputVat, 'Input VAT')->id, 'debit' => self::pesos($bill->vat_amount), 'credit' => '0', 'memo' => null];
            }
            if ($bill->ewt_amount > 0) {
                $lines[] = ['account_id' => $this->firstAccount(TaxRole::EwtPayable, 'Expanded withholding tax payable')->id, 'debit' => '0', 'credit' => self::pesos($bill->ewt_amount), 'memo' => null];
            }
            $payable = Account::query()->where('is_active', true)->where('control_type', ControlType::Payable->value)->orderBy('code')->get()
                ->first(fn ($a) => ! $a->children()->exists());
            if (! $payable) {
                throw new DomainRuleException('No active payable control account. Mark your trade payables account as a payable control account in the chart of accounts.');
            }
            $lines[] = ['account_id' => $payable->id, 'debit' => '0', 'credit' => self::pesos($bill->amount_due), 'contact_id' => $bill->contact_id, 'memo' => null];

            $number = $this->nextNumber();
            $entry = $this->journal->createDraft([
                'entry_date' => $bill->bill_date->format('Y-m-d'),
                'reference' => $bill->supplier_invoice_number,
                'description' => "Supplier bill {$number} - {$bill->contact->name} #{$bill->supplier_invoice_number}",
                'lines' => $lines,
            ], $bill->creator);
            $entry = $this->journal->post($entry, $user);
            JournalEntry::query()->whereKey($entry->id)->update(['source_type' => self::SOURCE, 'source_id' => $bill->id]);

            $bill->update([
                'number' => $number,
                'status' => InvoiceStatus::Issued,
                'journal_entry_id' => $entry->id,
                'issued_by' => $user->id,
                'issued_at' => now(),
            ]);

            return $this->load($bill);
        });
    }

    public function void(PurchaseBill $bill, User $user, string $reason): PurchaseBill
    {
        return DB::transaction(function () use ($bill, $user, $reason) {
            $bill = PurchaseBill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($bill->status !== InvoiceStatus::Issued || $bill->amount_paid > 0) {
                throw new DomainRuleException('Only recorded bills with no payments can be voided.');
            }
            $date = $bill->bill_date->format('Y-m-d');
            if ($this->periods->isClosed($date)) {
                $date = $this->periods->firstOpenDate();
            }
            $reversal = $this->journal->reverse($bill->journalEntry, $user, $date, "Bill {$bill->number} voided: {$reason}", fromDocument: true);
            JournalEntry::query()->whereKey($reversal->id)->update(['source_type' => self::SOURCE, 'source_id' => $bill->id]);

            $bill->update(['status' => InvoiceStatus::Voided, 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);

            return $this->load($bill);
        });
    }

    public function load(PurchaseBill $bill): PurchaseBill
    {
        return $bill->refresh()->load([
            'contact', 'lines.account:id,code,name', 'lines.vatCode', 'lines.ewtCode', 'journalEntry:id,number',
            'creator:id,name', 'issuer:id,name', 'voider:id,name',
        ]);
    }

    private function assertUniqueSupplierInvoice(int $contactId, string $number, ?int $ignoreId): void
    {
        $exists = PurchaseBill::query()
            ->where('contact_id', $contactId)
            ->where('supplier_invoice_number', trim($number))
            ->where('status', '!=', InvoiceStatus::Voided->value)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->first();
        if ($exists) {
            $label = $exists->number ?? 'a draft';
            throw new DomainRuleException("This supplier invoice number is already on {$label}.", [
                'supplier_invoice_number' => ['Already recorded for this supplier.'],
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function buildLines(array $input): array
    {
        $accounts = Account::query()->findMany(array_column($input, 'account_id'))->keyBy('id');
        $codeIds = array_filter([...array_column($input, 'vat_code_id'), ...array_column($input, 'ewt_code_id')]);
        $codes = TaxCode::query()->findMany($codeIds)->keyBy('id');
        $lines = [];
        foreach (array_values($input) as $i => $l) {
            $account = $accounts->get($l['account_id']);
            $ok = $account && $account->is_active && in_array($account->type, [AccountType::Expense, AccountType::Asset], true)
                && $account->tax_role === null && $account->control_type === null && ! $account->children()->exists();
            if (! $ok) {
                throw new DomainRuleException('Bill lines post to active expense or asset accounts (not tax or control accounts).', [
                    "lines.$i.account_id" => ['Choose an expense or asset account.'],
                ]);
            }
            foreach (['vat_code_id' => TaxKind::Vat, 'ewt_code_id' => TaxKind::Ewt] as $field => $kind) {
                $code = ! empty($l[$field]) ? $codes->get($l[$field]) : null;
                if (! empty($l[$field]) && (! $code || $code->kind !== $kind || ! $code->is_active)) {
                    throw new DomainRuleException('Choose an active '.($kind === TaxKind::Vat ? 'VAT' : 'withholding').' code.', ["lines.$i.$field" => ['Choose an active code.']]);
                }
            }
            $amount = JournalEntryService::toCents($l['amount']);
            if ($amount === 0) {
                throw new DomainRuleException('Line amounts must be above zero.', ["lines.$i.amount" => ['Enter an amount above zero.']]);
            }
            $lines[] = [
                'description' => $l['description'], 'amount' => $amount, 'account_id' => $account->id,
                'vat_code_id' => $l['vat_code_id'] ?? null ?: null, 'ewt_code_id' => $l['ewt_code_id'] ?? null ?: null, 'position' => $i,
            ];
        }

        return $lines;
    }

    /** @return array{0:int, 1:int} VAT and withholding, grouped like the posted entry */
    private function taxesFor(array $lines): array
    {
        $codes = TaxCode::query()->findMany(array_filter([...array_column($lines, 'vat_code_id'), ...array_column($lines, 'ewt_code_id')]))->keyBy('id');
        $groups = [];
        foreach ($lines as $l) {
            $key = $l['account_id'].'|'.$l['vat_code_id'].'|'.$l['ewt_code_id'];
            $groups[$key] ??= ['amount' => 0, 'vat' => $l['vat_code_id'], 'ewt' => $l['ewt_code_id']];
            $groups[$key]['amount'] += $l['amount'];
        }
        $vat = $ewt = 0;
        foreach ($groups as $g) {
            $vat += $g['vat'] ? $codes->get($g['vat'])->taxOn($g['amount']) : 0;
            $ewt += $g['ewt'] ? $codes->get($g['ewt'])->taxOn($g['amount']) : 0;
        }

        return [$vat, $ewt];
    }

    private function firstAccount(TaxRole $role, string $label): Account
    {
        $account = Account::query()->where('is_active', true)->where('tax_role', $role->value)->orderBy('code')->get()
            ->first(fn ($a) => ! $a->children()->exists());
        if (! $account) {
            throw new DomainRuleException("No active {$label} account. Set the tax role \"{$label}\" on it in the chart of accounts.");
        }

        return $account;
    }

    private static function pesos(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function nextNumber(): string
    {
        $last = PurchaseBill::query()->whereNotNull('number')->lockForUpdate()->orderByDesc('number')->value('number');

        return sprintf('PB-%06d', $last ? (int) substr($last, 3) + 1 : 1);
    }
}
