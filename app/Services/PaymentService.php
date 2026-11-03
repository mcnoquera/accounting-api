<?php

namespace App\Services;

use App\Enums\ControlType;
use App\Enums\InvoiceStatus;
use App\Enums\TaxKind;
use App\Enums\TaxRole;
use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Customer receipts and supplier payments, allocated to specific invoices and bills.
 *
 * Receipt: Dr cash/bank (amount received), Dr creditable withholding tax (what the customer
 * withheld, per their Form 2307), Cr receivable (customer) for both. Payment: Dr payable
 * (supplier), Cr cash/bank. The whole amount hits the customer's/supplier's balance; the part
 * not allocated to documents stays as an unapplied credit that can be allocated later.
 */
class PaymentService
{
    public const SOURCE = 'payment';

    /** Allowed difference between entered and computed withholding (₱1). */
    private const WITHHOLDING_TOLERANCE = 100;

    public function __construct(private JournalEntryService $journal, private PeriodService $periods) {}

    public function record(string $direction, array $data, User $user): Payment
    {
        $receipt = $direction === Payment::RECEIPT;
        $contact = Contact::query()->findOrFail($data['contact_id']);
        if (! $contact->is_active || ! ($receipt ? $contact->type->isCustomer() : $contact->type->isSupplier())) {
            $who = $receipt ? 'customer' : 'supplier';
            throw new DomainRuleException("{$contact->name} isn't an active {$who}.", ['contact_id' => ["Choose an active {$who}."]]);
        }
        $cash = Account::query()->find($data['cash_account_id']);
        if (! $cash || ! $cash->is_cash || ! $cash->is_active) {
            throw new DomainRuleException('Choose an active cash or bank account.', ['cash_account_id' => ['Choose a cash or bank account.']]);
        }

        $amount = JournalEntryService::toCents($data['amount']);
        [$code, $base, $withheld] = $receipt ? $this->withholding($data) : [null, 0, 0];
        if ($amount + $withheld <= 0) {
            throw new DomainRuleException('Enter the amount received or paid.', ['amount' => ['Enter an amount above zero.']]);
        }

        return DB::transaction(function () use ($direction, $receipt, $data, $user, $contact, $cash, $amount, $code, $base, $withheld) {
            $control = Account::query()->where('is_active', true)
                ->where('control_type', $receipt ? ControlType::Receivable->value : ControlType::Payable->value)
                ->orderBy('code')->get()->first(fn ($a) => ! $a->children()->exists());
            if (! $control) {
                throw new DomainRuleException('No active '.($receipt ? 'receivable' : 'payable').' control account. Mark it in the chart of accounts.');
            }

            $pesos = fn (int $c) => intdiv($c, 100).'.'.str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
            if ($receipt) {
                $lines = [['account_id' => $cash->id, 'debit' => $pesos($amount), 'credit' => '0', 'memo' => null]];
                if ($withheld > 0) {
                    $cwt = Account::query()->where('is_active', true)->where('tax_role', TaxRole::CwtReceivable->value)->orderBy('code')->first();
                    if (! $cwt) {
                        throw new DomainRuleException('No active creditable withholding tax account. Set the tax role on it in the chart of accounts.');
                    }
                    $lines[] = ['account_id' => $cwt->id, 'debit' => $pesos($withheld), 'credit' => '0', 'memo' => "{$code->code} withheld by customer"];
                }
                $lines[] = ['account_id' => $control->id, 'debit' => '0', 'credit' => $pesos($amount + $withheld), 'contact_id' => $contact->id, 'memo' => null];
            } else {
                $lines = [
                    ['account_id' => $control->id, 'debit' => $pesos($amount), 'credit' => '0', 'contact_id' => $contact->id, 'memo' => null],
                    ['account_id' => $cash->id, 'debit' => '0', 'credit' => $pesos($amount), 'memo' => null],
                ];
            }

            $number = $this->nextNumber($receipt ? 'RC' : 'PY');
            $entry = $this->journal->createDraft([
                'entry_date' => $data['payment_date'],
                'reference' => $data['reference'] ?? $number,
                'description' => ($receipt ? 'Receipt ' : 'Payment ').$number.' - '.$contact->name,
                'lines' => $lines,
            ], $user);
            $entry = $this->journal->post($entry, $user);
            JournalEntry::query()->whereKey($entry->id)->update(['source_type' => self::SOURCE]);

            $payment = Payment::create([
                'number' => $number,
                'direction' => $direction,
                'contact_id' => $contact->id,
                'payment_date' => $data['payment_date'],
                'cash_account_id' => $cash->id,
                'amount' => $amount,
                'ewt_code_id' => $code?->id,
                'withholding_base' => $base,
                'withholding_amount' => $withheld,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'created_by' => $user->id,
            ]);
            JournalEntry::query()->whereKey($entry->id)->update(['source_id' => $payment->id]);

            $this->applyAllocations($payment, $data['allocations'] ?? []);

            return $this->load($payment);
        });
    }

    /** Allocates an existing payment's unapplied amount to more documents. */
    public function allocate(Payment $payment, array $allocations): Payment
    {
        return DB::transaction(function () use ($payment, $allocations) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === 'voided') {
                throw new DomainRuleException('A voided payment can\'t be allocated.');
            }
            $this->applyAllocations($payment, $allocations);

            return $this->load($payment);
        });
    }

    public function void(Payment $payment, User $user, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $user, $reason) {
            $payment = Payment::query()->lockForUpdate()->with('allocations')->findOrFail($payment->id);
            if ($payment->status === 'voided') {
                throw new DomainRuleException('This payment is already voided.');
            }
            $date = $payment->payment_date->format('Y-m-d');
            if ($this->periods->isClosed($date)) {
                $date = $this->periods->firstOpenDate();
            }
            $reversal = $this->journal->reverse($payment->journalEntry, $user, $date, "{$payment->number} voided: {$reason}", fromDocument: true);
            JournalEntry::query()->whereKey($reversal->id)->update(['source_type' => self::SOURCE, 'source_id' => $payment->id]);

            foreach ($payment->allocations as $allocation) {
                $doc = $this->lockDocument($allocation->document_type, $allocation->document_id);
                $this->setPaid($doc, $doc->amount_paid - $allocation->amount);
            }
            $payment->update(['status' => 'voided', 'voided_by' => $user->id, 'voided_at' => now(), 'void_reason' => $reason]);

            return $this->load($payment);
        });
    }

    public function load(Payment $payment): Payment
    {
        $payment->refresh()->load(['contact', 'cashAccount:id,code,name', 'ewtCode', 'allocations', 'journalEntry:id,number', 'creator:id,name', 'voider:id,name']);
        $payment->allocations->each(fn ($a) => $a->setAttribute('document', $a->document()?->only(['id', 'number', 'total_amount', 'amount_due', 'balance_due', 'status', 'due_date'])));

        return $payment;
    }

    /** Payments allocated to one invoice or bill, newest first. */
    public function forDocument(string $type, int $id): array
    {
        return Payment::query()
            ->whereHas('allocations', fn ($q) => $q->where('document_type', $type)->where('document_id', $id))
            ->with(['allocations' => fn ($q) => $q->where('document_type', $type)->where('document_id', $id)])
            ->orderByDesc('payment_date')
            ->get()
            ->map(fn ($p) => ['id' => $p->id, 'number' => $p->number, 'payment_date' => $p->payment_date->format('Y-m-d'), 'status' => $p->status, 'amount' => (int) $p->allocations->sum('amount')])
            ->all();
    }

    /** @return array{0:?TaxCode, 1:int, 2:int} ATC, base and amount withheld by the customer */
    private function withholding(array $data): array
    {
        $withheld = isset($data['withholding_amount']) && $data['withholding_amount'] !== null ? JournalEntryService::toCents($data['withholding_amount']) : 0;
        if ($withheld === 0) {
            return [null, 0, 0];
        }
        $code = isset($data['ewt_code_id']) ? TaxCode::query()->find($data['ewt_code_id']) : null;
        if (! $code || $code->kind !== TaxKind::Ewt || ! $code->is_active) {
            throw new DomainRuleException('Choose the ATC on the customer\'s Form 2307.', ['ewt_code_id' => ['Choose an active withholding code.']]);
        }
        $base = JournalEntryService::toCents($data['withholding_base'] ?? 0);
        if ($base === 0 || abs($code->taxOn($base) - $withheld) > self::WITHHOLDING_TOLERANCE) {
            throw new DomainRuleException(sprintf('Withholding should be %s%% of the income payment (%s).', $code->rate, number_format($code->taxOn($base) / 100, 2)), [
                'withholding_amount' => ['Doesn\'t match the base and rate.'],
            ]);
        }

        return [$code, $base, $withheld];
    }

    /** @param list<array{document_id:int, amount:string|int}> $allocations */
    private function applyAllocations(Payment $payment, array $allocations): void
    {
        $type = $payment->direction === Payment::RECEIPT ? 'sales_invoice' : 'purchase_bill';
        $available = $payment->settles() - (int) $payment->allocations()->sum('amount');
        foreach (array_values($allocations) as $i => $a) {
            $amount = JournalEntryService::toCents($a['amount']);
            if ($amount === 0) {
                continue;
            }
            $doc = $this->lockDocument($type, (int) $a['document_id']);
            $label = $doc?->number ?? 'That document';
            if (! $doc || $doc->contact_id !== $payment->contact_id || ! $doc->status->isOpen()) {
                throw new DomainRuleException("{$label} isn't an open ".($type === 'sales_invoice' ? 'invoice' : 'bill').' of '.$payment->contact->name.'.', [
                    "allocations.$i.document_id" => ['Choose an open document of this contact.'],
                ]);
            }
            if ($amount > $doc->balance_due) {
                throw new DomainRuleException("{$label} has only ".number_format($doc->balance_due / 100, 2).' left to pay.', ["allocations.$i.amount" => ['More than the balance due.']]);
            }
            if ($amount > $available) {
                throw new DomainRuleException('Allocations add up to more than the payment ('.number_format($payment->settles() / 100, 2).').', ["allocations.$i.amount" => ['More than is left to allocate.']]);
            }
            $payment->allocations()->create(['document_type' => $type, 'document_id' => $doc->id, 'amount' => $amount]);
            $this->setPaid($doc, $doc->amount_paid + $amount);
            $available -= $amount;
        }
    }

    private function lockDocument(string $type, int $id): SalesInvoice|PurchaseBill|null
    {
        return ($type === 'sales_invoice' ? SalesInvoice::query() : PurchaseBill::query())->lockForUpdate()->find($id);
    }

    private function setPaid(SalesInvoice|PurchaseBill $doc, int $paid): void
    {
        $due = $doc instanceof SalesInvoice ? $doc->total_amount : $doc->amount_due;
        $status = match (true) {
            $paid >= $due => InvoiceStatus::Paid,
            $paid > 0 => InvoiceStatus::PartiallyPaid,
            default => InvoiceStatus::Issued,
        };
        $doc->update(['amount_paid' => $paid, 'status' => $status]);
    }

    private function nextNumber(string $prefix): string
    {
        $last = Payment::query()->where('number', 'like', "{$prefix}-%")->lockForUpdate()->orderByDesc('number')->value('number');

        return sprintf('%s-%06d', $prefix, $last ? (int) substr($last, 3) + 1 : 1);
    }
}
