<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\EntryStatus;
use App\Enums\TaxRole;
use App\Models\JournalEntry;
use App\Models\Payment;
use Carbon\CarbonImmutable;

/**
 * Expanded withholding tax for a calendar quarter, from posted entries (supports BIR Forms
 * 0619-E for the first two months, 1601-EQ for the quarter, and the QAP payee list), plus
 * creditable withholding customers deducted from us (to match against their Forms 2307).
 *
 * The base is the amount of the line carrying the ATC (purchases: non-revenue lines; sales:
 * revenue lines; returns and reversals reduce it). The payee is the contact on that line, or
 * the only contact elsewhere on the entry (typically the payable/receivable line).
 * Amounts are cents.
 */
class WithholdingSummaryService
{
    public function summary(int $year, int $quarter): array
    {
        $from = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1);
        $to = $from->addMonths(2)->endOfMonth();

        $monthKeys = [];
        for ($m = $from; $m <= $to; $m = $m->addMonth()) {
            $monthKeys[] = $m->format('Y-m');
        }

        $withheld = [];  // [atc] => row with months
        $payees = [];    // [contact|atc] => row
        $received = [];  // [contact|atc] => row
        $booked = ['ewt_payable' => 0, 'cwt_receivable' => 0];
        $other = [];

        $entries = JournalEntry::query()
            ->with(['lines.account', 'lines.ewtCode', 'lines.contact'])
            ->where('status', EntryStatus::Posted)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $month = $entry->entry_date->format('Y-m');
            // Receipts carry the customer's withholding on the receipt itself, not as a code on a line.
            $coded = $entry->lines->contains(fn ($l) => $l->ewt_code_id !== null) || $entry->source_type === 'payment';
            $entryContacts = $entry->lines->pluck('contact')->filter()->unique('id');

            foreach ($entry->lines as $line) {
                $role = $line->account->tax_role;
                if (in_array($role, [TaxRole::EwtPayable, TaxRole::CwtReceivable], true)) {
                    $amount = $role === TaxRole::EwtPayable ? $line->credit - $line->debit : $line->debit - $line->credit;
                    if ($coded) {
                        $booked[$role->value] += $amount;
                    } else {
                        $other[] = [
                            'entry_id' => $entry->id, 'number' => $entry->number, 'entry_date' => $entry->entry_date->format('Y-m-d'),
                            'description' => $entry->description, 'account_code' => $line->account->code,
                            'role' => $role->value, 'amount' => $amount,
                        ];
                    }
                }

                $code = $line->ewtCode;
                if (! $code) {
                    continue;
                }
                $isSale = $line->account->type === AccountType::Revenue;
                $base = $isSale ? $line->credit - $line->debit : $line->debit - $line->credit;
                $tax = $code->taxOn(abs($base)) * ($base < 0 ? -1 : 1);
                $contact = $line->contact ?? ($entryContacts->count() === 1 ? $entryContacts->first() : null);
                $party = [
                    'contact_id' => $contact?->id,
                    'name' => $contact?->name,
                    'tin' => $contact?->tin,
                    'atc' => $code->code,
                    'description' => $code->name,
                    'rate' => $code->rate,
                ];
                $key = ($contact?->id ?? 0).'|'.$code->code;

                if ($isSale) {
                    $received[$key] ??= $party + ['base' => 0, 'tax' => 0];
                    $received[$key]['base'] += $base;
                    $received[$key]['tax'] += $tax;

                    continue;
                }

                $withheld[$code->code] ??= [
                    'atc' => $code->code, 'description' => $code->name, 'rate' => $code->rate,
                    'months' => array_fill_keys($monthKeys, ['base' => 0, 'tax' => 0]),
                    'base' => 0, 'tax' => 0,
                ];
                $withheld[$code->code]['months'][$month]['base'] += $base;
                $withheld[$code->code]['months'][$month]['tax'] += $tax;
                $withheld[$code->code]['base'] += $base;
                $withheld[$code->code]['tax'] += $tax;

                $payees[$key] ??= $party + ['base' => 0, 'tax' => 0];
                $payees[$key]['base'] += $base;
                $payees[$key]['tax'] += $tax;
            }
        }

        // Creditable withholding customers deducted when paying us (receipts with a Form 2307).
        $receipts = Payment::query()->with(['contact', 'ewtCode'])
            ->where('direction', Payment::RECEIPT)->where('status', 'posted')->where('withholding_amount', '>', 0)
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->get();
        foreach ($receipts as $p) {
            $key = $p->contact_id.'|'.$p->ewtCode->code;
            $received[$key] ??= [
                'contact_id' => $p->contact_id, 'name' => $p->contact->name, 'tin' => $p->contact->tin,
                'atc' => $p->ewtCode->code, 'description' => $p->ewtCode->name, 'rate' => $p->ewtCode->rate, 'base' => 0, 'tax' => 0,
            ];
            $received[$key]['base'] += $p->withholding_base;
            $received[$key]['tax'] += $p->withholding_amount;
        }

        ksort($withheld);
        $sortParties = function (array $rows): array {
            $rows = array_values($rows);
            // By payee name (unassigned last), then ATC.
            usort($rows, fn ($a, $b) => [$a['name'] === null, strtolower((string) $a['name']), $a['atc']]
                <=> [$b['name'] === null, strtolower((string) $b['name']), $b['atc']]);

            return $rows;
        };

        $monthTotals = [];
        foreach ($monthKeys as $m) {
            $monthTotals[] = [
                'month' => $m,
                'base' => array_sum(array_map(fn ($r) => $r['months'][$m]['base'], $withheld)),
                'tax' => array_sum(array_map(fn ($r) => $r['months'][$m]['tax'], $withheld)),
            ];
        }
        $payees = $sortParties($payees);
        $received = $sortParties($received);

        return [
            'year' => $year,
            'quarter' => $quarter,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'withheld' => [
                'by_atc' => array_values(array_map(function ($r) {
                    $r['months'] = array_map(fn ($k, $v) => ['month' => $k] + $v, array_keys($r['months']), $r['months']);

                    return $r;
                }, $withheld)),
                'months' => $monthTotals,
                'total' => ['base' => array_sum(array_column($withheld, 'base')), 'tax' => array_sum(array_column($withheld, 'tax'))],
                'booked' => $booked['ewt_payable'],
                'payees' => $payees,
                'payees_without_contact' => count(array_filter($payees, fn ($p) => $p['contact_id'] === null)),
            ],
            'received' => [
                'by_customer' => $received,
                'total' => ['base' => array_sum(array_column($received, 'base')), 'tax' => array_sum(array_column($received, 'tax'))],
                'booked' => $booked['cwt_receivable'],
            ],
            'other_activity' => $other,
        ];
    }
}
