<?php

namespace App\Services;

use App\Enums\ControlType;
use App\Enums\EntryStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Models\Contact;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Customers and suppliers, their subsidiary ledgers, and receivable/payable aging.
 *
 * Only posted lines on control accounts count towards a contact's balance. Without invoice
 * matching, aging applies payments and credits to the oldest open charges first, and each
 * charge falls due on its entry date plus the contact's payment terms. Amounts are cents.
 */
class ContactService
{
    public const BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'];

    public function delete(Contact $contact): void
    {
        if ($contact->lines()->exists()) {
            throw new DomainRuleException("{$contact->name} is used on journal entries and can't be deleted. Deactivate it instead.");
        }
        $contact->delete();
    }

    /** Which control type a contact's ledger shows by default. */
    public function defaultControl(Contact $contact): ControlType
    {
        return $contact->type->isCustomer() ? ControlType::Receivable : ControlType::Payable;
    }

    public function ledger(Contact $contact, ControlType $control, ?string $from, ?string $to): array
    {
        $lines = $this->lines($control, $to, $contact->id);
        $opening = 0;
        $rows = [];
        $running = 0;
        foreach ($lines as $l) {
            $amount = $control->balance($l->debit, $l->credit);
            if ($from && $l->entry_date < $from) {
                $opening += $amount;
                $running = $opening;

                continue;
            }
            $running += $amount;
            $rows[] = [
                'entry_id' => $l->entry_id,
                'number' => $l->number,
                'entry_date' => $l->entry_date,
                'description' => $l->description,
                'memo' => $l->memo,
                'account_code' => $l->account_code,
                'debit' => (int) $l->debit,
                'credit' => (int) $l->credit,
                'balance' => $running,
            ];
        }

        return [
            'contact' => $contact,
            'control_type' => $control->value,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'rows' => $rows,
            'closing_balance' => $running,
        ];
    }

    /**
     * Balance per contact split into aging buckets as of a date, plus the control accounts'
     * balance so lines without a contact show up as a difference.
     */
    public function aging(ControlType $control, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $asOfDate = CarbonImmutable::parse($asOf);
        $contacts = Contact::query()->get()->keyBy('id');

        $byContact = $this->lines($control, $asOf)->whereNotNull('contact_id')->groupBy('contact_id');
        $rows = [];
        foreach ($byContact as $contactId => $lines) {
            $contact = $contacts->get($contactId);
            $buckets = $this->ageLines($lines, $control, $contact->payment_terms_days, $asOfDate);
            $total = array_sum($buckets);
            if ($total === 0 && ! array_filter($buckets)) {
                continue;
            }
            $rows[] = [
                'contact_id' => $contact->id,
                'name' => $contact->name,
                'tin' => $contact->tin,
                'payment_terms_days' => $contact->payment_terms_days,
                ...$buckets,
                'total' => $total,
            ];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $totals = array_fill_keys([...self::BUCKETS, 'total'], 0);
        foreach ($rows as $r) {
            foreach (array_keys($totals) as $k) {
                $totals[$k] += $r[$k];
            }
        }

        $accountIds = $this->controlAccountIds($control);
        $gl = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', EntryStatus::Posted->value)
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->whereIn('journal_lines.account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as d, COALESCE(SUM(journal_lines.credit), 0) as c')
            ->first();
        $controlBalance = $control->balance((int) $gl->d, (int) $gl->c);

        return [
            'type' => $control->value,
            'as_of' => $asOf,
            'rows' => $rows,
            'totals' => $totals,
            'control_accounts' => Account::query()->whereIn('id', $accountIds)->orderBy('code')->get(['id', 'code', 'name']),
            'control_balance' => $controlBalance,
            'unassigned' => $controlBalance - $totals['total'],
        ];
    }

    /**
     * Ages one contact's lines (oldest first): charges open in order; payments and credits
     * settle the oldest open charges, and any excess is an unapplied credit shown as current.
     *
     * @return array<string, int>
     */
    private function ageLines(Collection $lines, ControlType $control, int $terms, CarbonImmutable $asOf): array
    {
        $open = [];   // list of [due date, remaining]
        $credit = 0;  // unapplied payments/credits (positive number)
        foreach ($lines as $l) {
            $amount = $control->balance((int) $l->debit, (int) $l->credit);
            if ($amount > 0) {
                $applied = min($credit, $amount);
                $credit -= $applied;
                if ($amount - $applied > 0) {
                    $open[] = [CarbonImmutable::parse($l->entry_date)->addDays($terms), $amount - $applied];
                }

                continue;
            }
            $pay = -$amount;
            while ($pay > 0 && $open !== []) {
                $take = min($pay, $open[0][1]);
                $open[0][1] -= $take;
                $pay -= $take;
                if ($open[0][1] === 0) {
                    array_shift($open);
                }
            }
            $credit += $pay;
        }

        $buckets = array_fill_keys(self::BUCKETS, 0);
        foreach ($open as [$due, $remaining]) {
            $overdue = (int) $due->diffInDays($asOf, false);
            $key = match (true) {
                $overdue <= 0 => 'current',
                $overdue <= 30 => 'days_1_30',
                $overdue <= 60 => 'days_31_60',
                $overdue <= 90 => 'days_61_90',
                default => 'over_90',
            };
            $buckets[$key] += $remaining;
        }
        $buckets['current'] -= $credit;

        return $buckets;
    }

    /** Posted lines on the control accounts, oldest first. */
    private function lines(ControlType $control, ?string $to, ?int $contactId = null): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.status', EntryStatus::Posted->value)
            ->where('accounts.control_type', $control->value)
            ->when($to, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $to))
            ->when($contactId, fn ($q) => $q->where('journal_lines.contact_id', $contactId))
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->get([
                'journal_lines.contact_id', 'journal_lines.debit', 'journal_lines.credit', 'journal_lines.memo',
                'journal_entries.id as entry_id', 'journal_entries.number', 'journal_entries.description',
                'journal_entries.entry_date', 'accounts.code as account_code',
            ])
            // Raw date values differ by driver (e.g. SQLite adds a time); keep Y-m-d.
            ->each(fn ($l) => $l->entry_date = substr((string) $l->getRawOriginal('entry_date'), 0, 10));
    }

    /** @return list<int> */
    private function controlAccountIds(ControlType $control): array
    {
        return Account::query()->where('control_type', $control->value)->pluck('id')->all();
    }
}
