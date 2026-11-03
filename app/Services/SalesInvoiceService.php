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
use App\Models\SalesInvoice;
use App\Models\TaxCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sales invoices. A draft is free to edit; issuing numbers it (SI-000001, gap-free) and posts
 * an ordinary journal entry through JournalEntryService, so the period lock, contact and tax
 * rules all apply: Dr receivable (customer) for the total, Cr revenue per account and VAT
 * code, Cr output VAT. Voiding an unpaid invoice reverses that entry.
 */
class SalesInvoiceService
{
    public const SOURCE = 'sales_invoice';

    public function __construct(private JournalEntryService $journal, private PeriodService $periods) {}

    /** @param array{contact_id:int, invoice_date:string, due_date?:?string, reference?:?string, notes?:?string, lines:list<array>} $data */
    public function saveDraft(array $data, User $user, ?SalesInvoice $invoice = null): SalesInvoice
    {
        if ($invoice && $invoice->status !== InvoiceStatus::Draft) {
            throw new DomainRuleException('Only draft invoices can be edited.');
        }
        $customer = Contact::query()->findOrFail($data['contact_id']);
        if (! $customer->type->isCustomer() || ! $customer->is_active) {
            throw new DomainRuleException("{$customer->name} isn't an active customer.", ['contact_id' => ['Choose an active customer.']]);
        }
        $lines = $this->buildLines($data['lines']);
        $net = array_sum(array_column($lines, 'amount'));
        $vat = $this->vatFor($lines);

        return DB::transaction(function () use ($data, $user, $invoice, $customer, $lines, $net, $vat) {
            $attributes = [
                'contact_id' => $customer->id,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? CarbonImmutable::parse($data['invoice_date'])->addDays($customer->payment_terms_days)->toDateString(),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'net_amount' => $net,
                'vat_amount' => $vat,
                'total_amount' => $net + $vat,
            ];
            if ($invoice) {
                $invoice->update($attributes);
                $invoice->lines()->delete();
            } else {
                $invoice = SalesInvoice::create($attributes + ['status' => InvoiceStatus::Draft, 'created_by' => $user->id]);
            }
            $invoice->lines()->createMany($lines);

            return $this->load($invoice);
        });
    }

    public function delete(SalesInvoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new DomainRuleException('Only draft invoices can be deleted. Void an issued invoice instead.');
        }
        $invoice->delete();
    }

    public function issue(SalesInvoice $invoice, User $user): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $user) {
            $invoice = SalesInvoice::query()->lockForUpdate()->with('lines.vatCode')->findOrFail($invoice->id);
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw new DomainRuleException('Only draft invoices can be issued.');
            }
            if ($invoice->total_amount <= 0) {
                throw new DomainRuleException('An invoice needs a total above zero.');
            }

            $receivable = $this->firstAccount(fn ($q) => $q->where('control_type', ControlType::Receivable->value),
                'No active receivable control account. Mark your trade receivables account as a receivable control account in the chart of accounts.');
            $lines = [[
                'account_id' => $receivable->id, 'debit' => self::pesos($invoice->total_amount), 'credit' => '0',
                'contact_id' => $invoice->contact_id, 'memo' => null,
            ]];
            // One revenue line per account and VAT code keeps the entry readable.
            foreach ($invoice->lines->groupBy(fn ($l) => $l->account_id.'|'.$l->vat_code_id) as $group) {
                $lines[] = [
                    'account_id' => $group->first()->account_id, 'debit' => '0', 'credit' => self::pesos($group->sum('amount')),
                    'vat_code_id' => $group->first()->vat_code_id, 'memo' => null,
                ];
            }
            if ($invoice->vat_amount > 0) {
                $outputVat = $this->firstAccount(fn ($q) => $q->where('tax_role', TaxRole::OutputVat->value),
                    'No active Output VAT account. Set the tax role "Output VAT" on it in the chart of accounts.');
                $lines[] = ['account_id' => $outputVat->id, 'debit' => '0', 'credit' => self::pesos($invoice->vat_amount), 'memo' => null];
            }

            $number = $this->nextNumber();
            $entry = $this->journal->createDraft([
                'entry_date' => $invoice->invoice_date->format('Y-m-d'),
                'reference' => $number,
                'description' => "Sales invoice {$number} - {$invoice->contact->name}",
                'lines' => $lines,
            ], $invoice->creator);
            $entry = $this->journal->post($entry, $user);
            JournalEntry::query()->whereKey($entry->id)->update(['source_type' => self::SOURCE, 'source_id' => $invoice->id]);

            $invoice->update([
                'number' => $number,
                'status' => InvoiceStatus::Issued,
                'journal_entry_id' => $entry->id,
                'issued_by' => $user->id,
                'issued_at' => now(),
            ]);

            return $this->load($invoice);
        });
    }

    public function void(SalesInvoice $invoice, User $user, string $reason): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $user, $reason) {
            $invoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->is_opening) {
                throw new DomainRuleException('Opening invoices change only by replacing the opening balances.');
            }
            if ($invoice->status !== InvoiceStatus::Issued || $invoice->amount_paid > 0) {
                throw new DomainRuleException('Only issued invoices with no payments can be voided.');
            }
            $date = $invoice->invoice_date->format('Y-m-d');
            if ($this->periods->isClosed($date)) {
                $date = $this->periods->firstOpenDate();
            }
            $reversal = $this->journal->reverse($invoice->journalEntry, $user, $date, "Invoice {$invoice->number} voided: {$reason}", fromDocument: true);
            JournalEntry::query()->whereKey($reversal->id)->update(['source_type' => self::SOURCE, 'source_id' => $invoice->id]);

            $invoice->update([
                'status' => InvoiceStatus::Voided,
                'voided_by' => $user->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $this->load($invoice);
        });
    }

    public function load(SalesInvoice $invoice): SalesInvoice
    {
        return $invoice->refresh()->load([
            'contact', 'lines.account:id,code,name', 'lines.vatCode', 'journalEntry:id,number',
            'creator:id,name', 'issuer:id,name', 'voider:id,name',
        ]);
    }

    /** 1.5 → 1500 */
    public static function toMilli(string|int|float $quantity): int
    {
        $s = trim((string) $quantity);
        if (! preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $s, $m)) {
            throw new DomainRuleException("Invalid quantity: {$s}", ['lines' => ['Quantities are positive numbers with at most 3 decimals.']]);
        }

        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '0', 3, '0');
    }

    private static function pesos(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @return list<array<string, mixed>> */
    private function buildLines(array $input): array
    {
        $accounts = Account::query()->findMany(array_column($input, 'account_id'))->keyBy('id');
        $codes = TaxCode::query()->findMany(array_filter(array_column($input, 'vat_code_id')))->keyBy('id');
        $lines = [];
        foreach (array_values($input) as $i => $l) {
            $account = $accounts->get($l['account_id']);
            if (! $account || $account->type !== AccountType::Revenue || ! $account->is_active || $account->children()->exists()) {
                throw new DomainRuleException('Invoice lines post to active revenue accounts.', ["lines.$i.account_id" => ['Choose an active revenue account.']]);
            }
            $code = isset($l['vat_code_id']) && $l['vat_code_id'] ? $codes->get($l['vat_code_id']) : null;
            if ($code && ($code->kind !== TaxKind::Vat || ! $code->is_active)) {
                throw new DomainRuleException('Choose an active VAT code.', ["lines.$i.vat_code_id" => ['Choose an active VAT code.']]);
            }
            $qty = self::toMilli($l['quantity']);
            $price = JournalEntryService::toCents($l['unit_price']);
            if ($qty === 0) {
                throw new DomainRuleException('Quantity must be above zero.', ["lines.$i.quantity" => ['Quantity must be above zero.']]);
            }
            $lines[] = [
                'description' => $l['description'],
                'quantity_milli' => $qty,
                'unit_price' => $price,
                'amount' => intdiv($qty * $price + 500, 1000),
                'account_id' => $account->id,
                'vat_code_id' => $code?->id,
                'position' => $i,
            ];
        }

        return $lines;
    }

    /** VAT per revenue account and code, matching how the journal entry groups lines. */
    private function vatFor(array $lines): int
    {
        $codes = TaxCode::query()->findMany(array_filter(array_column($lines, 'vat_code_id')))->keyBy('id');
        $groups = [];
        foreach ($lines as $l) {
            $groups[$l['account_id'].'|'.$l['vat_code_id']] = ($groups[$l['account_id'].'|'.$l['vat_code_id']] ?? 0) + $l['amount'];
        }
        $vat = 0;
        foreach ($groups as $key => $amount) {
            $codeId = (int) explode('|', $key)[1];
            $vat += $codeId ? $codes->get($codeId)->taxOn($amount) : 0;
        }

        return $vat;
    }

    private function firstAccount(callable $scope, string $error): Account
    {
        $account = $scope(Account::query()->where('is_active', true))->orderBy('code')->get()
            ->first(fn ($a) => ! $a->children()->exists());
        if (! $account) {
            throw new DomainRuleException($error);
        }

        return $account;
    }

    private function nextNumber(): string
    {
        $last = SalesInvoice::query()->whereNotNull('number')->lockForUpdate()->orderByDesc('number')->value('number');

        return sprintf('SI-%06d', $last ? (int) substr($last, 3) + 1 : 1);
    }
}
