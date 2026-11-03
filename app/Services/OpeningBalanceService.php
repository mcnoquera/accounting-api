<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\ControlType;
use App\Enums\EntryStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\OpeningBalance;
use App\Models\PaymentAllocation;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Opening balances for a business moving onto Ledgerly. One posted, system entry dated the
 * day before the cut-over date carries every account's balance; receivables and payables come
 * from opening invoices and bills per customer/supplier (so aging and payments work at once);
 * any difference goes to an Opening Balance Equity account. Every month before cut-over is
 * then closed. Replaceable (same cut-over date) until an opening document is paid or the
 * cut-over month is closed.
 */
class OpeningBalanceService
{
    public const SOURCE = 'opening_balance';

    public function __construct(
        private PeriodService $periods,
        private JournalEntryRepositoryInterface $entries,
    ) {}

    public function current(): ?array
    {
        $ob = OpeningBalance::query()->with(['equityAccount:id,code,name', 'journalEntry.lines.account:id,code,name,type'])->first();
        if (! $ob) {
            return null;
        }

        return [
            'cutover_date' => $ob->cutover_date->format('Y-m-d'),
            'as_of' => $ob->cutover_date->subDay()->format('Y-m-d'),
            'equity_account' => $ob->equityAccount,
            'journal_entry' => $ob->journalEntry->only(['id', 'number']),
            'lines' => $ob->journalEntry->lines->filter(fn ($l) => $l->contact_id === null && $l->account_id !== $ob->equity_account_id)
                ->map(fn ($l) => ['account_id' => $l->account_id, 'code' => $l->account->code, 'name' => $l->account->name, 'debit' => $l->debit, 'credit' => $l->credit])->values(),
            'receivables' => SalesInvoice::query()->where('is_opening', true)->with('contact:id,name')->orderBy('id')->get(['id', 'number', 'reference', 'contact_id', 'invoice_date', 'due_date', 'total_amount', 'amount_paid', 'status']),
            'payables' => PurchaseBill::query()->where('is_opening', true)->with('contact:id,name')->orderBy('id')->get(['id', 'number', 'supplier_invoice_number', 'contact_id', 'bill_date', 'due_date', 'amount_due', 'amount_paid', 'status']),
            'replaceable' => $this->replaceBlocker($ob) === null,
        ];
    }

    /**
     * @param  array{cutover_date:string, equity_account_id?:?int, lines?:list<array>, receivables?:list<array>, payables?:list<array>}  $data
     */
    public function save(array $data, User $user): array
    {
        $cutover = CarbonImmutable::parse($data['cutover_date']);
        if ($cutover->day !== 1) {
            throw new DomainRuleException('The cut-over date is the first day of a month (your first month in Ledgerly).', ['cutover_date' => ['Use the 1st of a month.']]);
        }
        $asOf = $cutover->subDay()->toDateString();

        DB::transaction(function () use ($data, $user, $cutover, $asOf) {
            $existing = OpeningBalance::query()->lockForUpdate()->first();
            if ($existing) {
                if ($existing->cutover_date->format('Y-m-d') !== $cutover->toDateString()) {
                    throw new DomainRuleException('Opening balances were entered for cut-over '.$existing->cutover_date->format('Y-m-d').'. Replace them with the same cut-over date.');
                }
                if ($blocker = $this->replaceBlocker($existing)) {
                    throw new DomainRuleException($blocker);
                }
                $this->remove($existing);
            }

            $earlier = JournalEntry::query()->whereDate('entry_date', '<', $cutover->toDateString())->exists();
            if ($earlier) {
                throw new DomainRuleException('There are already entries dated before the cut-over date. Opening balances replace everything before cut-over, so start from an empty period.');
            }

            $equity = $this->equityAccount($data['equity_account_id'] ?? null);
            [$lines, $receivables, $payables] = $this->buildLines($data, $asOf);

            $net = array_sum(array_column($lines, 'debit')) - array_sum(array_column($lines, 'credit'));
            if ($net !== 0) {
                $lines[] = ['account_id' => $equity->id, 'debit' => $net < 0 ? -$net : 0, 'credit' => $net > 0 ? $net : 0, 'memo' => 'Opening balance difference', 'contact_id' => null];
            }
            if (count($lines) < 2) {
                throw new DomainRuleException('Enter at least one opening balance.');
            }

            $entry = $this->entries->create([
                'number' => $this->entries->nextNumber(),
                'entry_date' => $asOf,
                'reference' => 'OPENING',
                'description' => 'Opening balances as of '.CarbonImmutable::parse($asOf)->format('M j, Y'),
                'status' => EntryStatus::Posted,
                'created_by' => $user->id,
                'posted_by' => $user->id,
                'posted_at' => now(),
                'source_type' => self::SOURCE,
            ], $lines);

            foreach ($receivables as $r) {
                SalesInvoice::create([
                    'number' => $this->nextNumber(SalesInvoice::class, 'OI'), 'reference' => $r['number'], 'contact_id' => $r['contact_id'],
                    'invoice_date' => $r['date'], 'due_date' => $r['due_date'], 'status' => InvoiceStatus::Issued, 'is_opening' => true,
                    'net_amount' => $r['amount'], 'vat_amount' => 0, 'total_amount' => $r['amount'], 'journal_entry_id' => $entry->id,
                    'created_by' => $user->id, 'issued_by' => $user->id, 'issued_at' => now(), 'notes' => 'Opening balance',
                ]);
            }
            foreach ($payables as $p) {
                PurchaseBill::create([
                    'number' => $this->nextNumber(PurchaseBill::class, 'OB'), 'supplier_invoice_number' => $p['number'], 'contact_id' => $p['contact_id'],
                    'bill_date' => $p['date'], 'due_date' => $p['due_date'], 'status' => InvoiceStatus::Issued, 'is_opening' => true,
                    'net_amount' => $p['amount'], 'vat_amount' => 0, 'ewt_amount' => 0, 'total_amount' => $p['amount'], 'amount_due' => $p['amount'],
                    'journal_entry_id' => $entry->id, 'created_by' => $user->id, 'issued_by' => $user->id, 'issued_at' => now(), 'notes' => 'Opening balance',
                ]);
            }

            OpeningBalance::create(['cutover_date' => $cutover->toDateString(), 'journal_entry_id' => $entry->id, 'equity_account_id' => $equity->id, 'created_by' => $user->id]);

            // Lock everything before cut-over so opening figures can't be disturbed.
            $guard = 0;
            while (($this->periods->lockedThrough() ?? '0000-00-00') < $asOf && ($next = $this->periods->nextToClose()) && $guard++ < 600) {
                $this->periods->close($next, $user);
            }
        });

        return $this->current();
    }

    private function replaceBlocker(OpeningBalance $ob): ?string
    {
        $docIds = fn (string $model) => $model::query()->where('is_opening', true)->pluck('id');
        $paid = PaymentAllocation::query()->whereHas('payment', fn ($q) => $q->where('status', 'posted'))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('document_type', 'sales_invoice')->whereIn('document_id', $docIds(SalesInvoice::class)))
                ->orWhere(fn ($w) => $w->where('document_type', 'purchase_bill')->whereIn('document_id', $docIds(PurchaseBill::class))))
            ->exists();
        if ($paid) {
            return 'An opening invoice or bill already has payments, so the opening balances can no longer be replaced.';
        }
        if (($this->periods->lockedThrough() ?? '') >= $ob->cutover_date->endOfMonth()->format('Y-m-d')) {
            return 'The cut-over month is closed, so the opening balances can no longer be replaced.';
        }

        return null;
    }

    private function remove(OpeningBalance $ob): void
    {
        SalesInvoice::query()->where('is_opening', true)->get()->each->delete();
        PurchaseBill::query()->where('is_opening', true)->get()->each->delete();
        $entry = $ob->journalEntry;
        $ob->delete();
        $entry->lines()->delete();
        $entry->delete();
    }

    private function equityAccount(?int $id): Account
    {
        if ($id) {
            $a = Account::query()->find($id);
            if (! $a || $a->type !== AccountType::Equity || ! $a->is_active || $a->children()->exists()) {
                throw new DomainRuleException('Choose an active equity detail account for the opening difference.', ['equity_account_id' => ['Must be an active equity account.']]);
            }

            return $a;
        }
        $existing = Account::query()->where('code', '3900')->first();
        if ($existing && $existing->type !== AccountType::Equity) {
            throw new DomainRuleException('Account 3900 isn\'t an equity account. Choose the account for the opening difference.', ['equity_account_id' => ['Choose an equity account.']]);
        }

        return $existing ?? Account::create(['code' => '3900', 'name' => 'Opening Balance Equity', 'type' => AccountType::Equity, 'description' => 'Difference from opening balances at cut-over.']);
    }

    /** @return array{0: list<array>, 1: list<array>, 2: list<array>} journal lines, receivables, payables */
    private function buildLines(array $data, string $asOf): array
    {
        $lines = [];
        $accounts = Account::query()->findMany(array_column($data['lines'] ?? [], 'account_id'))->keyBy('id');
        foreach (array_values($data['lines'] ?? []) as $i => $l) {
            $debit = JournalEntryService::toCents($l['debit'] ?? 0);
            $credit = JournalEntryService::toCents($l['credit'] ?? 0);
            if ($debit === 0 && $credit === 0) {
                continue;
            }
            $a = $accounts->get($l['account_id']);
            if (! $a || ! $a->is_active || $a->children()->exists()) {
                throw new DomainRuleException('Opening balances go on active detail accounts.', ["lines.$i.account_id" => ['Choose an active detail account.']]);
            }
            if ($a->control_type !== null) {
                throw new DomainRuleException("{$a->code} {$a->name} is a control account: enter its balance as opening ".($a->control_type === ControlType::Receivable ? 'invoices per customer' : 'bills per supplier').'.', [
                    "lines.$i.account_id" => ['Use the receivables/payables lists for this account.'],
                ]);
            }
            if ($debit > 0 && $credit > 0) {
                throw new DomainRuleException('A line cannot have both a debit and a credit.', ["lines.$i" => ['Enter either a debit or a credit.']]);
            }
            $lines[] = ['account_id' => $a->id, 'debit' => $debit, 'credit' => $credit, 'memo' => 'Opening balance', 'contact_id' => null];
        }

        $docs = [];
        foreach (['receivables' => ControlType::Receivable, 'payables' => ControlType::Payable] as $key => $control) {
            $docs[$key] = [];
            $items = $data[$key] ?? [];
            if ($items === []) {
                continue;
            }
            $account = Account::query()->where('is_active', true)->where('control_type', $control->value)->orderBy('code')->get()->first(fn ($a) => ! $a->children()->exists());
            if (! $account) {
                throw new DomainRuleException("No active {$control->value} control account for opening {$key}.");
            }
            $contacts = Contact::query()->findMany(array_column($items, 'contact_id'))->keyBy('id');
            foreach (array_values($items) as $i => $d) {
                $c = $contacts->get($d['contact_id']);
                if (! $c || ! $control->accepts($c->type)) {
                    throw new DomainRuleException('Opening '.$key.' need a '.$control->contactLabel().' on every row.', ["$key.$i.contact_id" => ['Choose a '.$control->contactLabel().'.']]);
                }
                $amount = JournalEntryService::toCents($d['amount']);
                $date = $d['date'];
                if ($amount === 0 || $date > $asOf) {
                    throw new DomainRuleException("Opening {$key} need an amount and a date on or before {$asOf}.", ["$key.$i" => ['Amount above zero, dated before cut-over.']]);
                }
                $due = $d['due_date'] ?? CarbonImmutable::parse($date)->addDays($c->payment_terms_days)->toDateString();
                $docs[$key][] = ['contact_id' => $c->id, 'number' => $d['number'], 'date' => $date, 'due_date' => $due, 'amount' => $amount];
                $lines[] = [
                    'account_id' => $account->id,
                    'debit' => $control === ControlType::Receivable ? $amount : 0,
                    'credit' => $control === ControlType::Payable ? $amount : 0,
                    'memo' => "Opening {$d['number']}", 'contact_id' => $c->id,
                ];
            }
        }

        return [$lines, $docs['receivables'], $docs['payables']];
    }

    private function nextNumber(string $model, string $prefix): string
    {
        $last = $model::query()->where('number', 'like', "{$prefix}-%")->orderByDesc('number')->value('number');

        return sprintf('%s-%06d', $prefix, $last ? (int) substr($last, 3) + 1 : 1);
    }
}
