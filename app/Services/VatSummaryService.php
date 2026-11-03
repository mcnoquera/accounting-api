<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\EntryStatus;
use App\Enums\TaxKind;
use App\Enums\TaxRole;
use App\Enums\VatCategory;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;

/**
 * Quarterly VAT summary (supports BIR Form 2550Q) for a calendar quarter, from posted entries.
 *
 * Sales and purchases come from VAT-coded base lines (revenue lines are sales; returns and
 * reversals reduce them). Output and input VAT come from the VAT-account lines of those coded
 * entries. VAT-account activity in entries without any VAT code (quarter-end settlements,
 * manual adjustments, mistakes) is listed separately so the totals can be reconciled.
 * All amounts are integer cents.
 */
class VatSummaryService
{
    public function summary(int $year, int $quarter): array
    {
        $from = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1);
        $to = $from->addMonths(2)->endOfMonth();

        $months = [];
        for ($m = $from; $m <= $to; $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = $this->emptyBucket() + ['month' => $m->format('Y-m')];
        }
        $other = [];

        $entries = JournalEntry::query()
            ->with(['lines.account', 'lines.vatCode'])
            ->where('status', EntryStatus::Posted)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $key = $entry->entry_date->format('Y-m');
            $coded = $entry->lines->contains(fn ($l) => $l->vatCode?->kind === TaxKind::Vat);

            foreach ($entry->lines as $line) {
                $account = $line->account;
                $code = $line->vatCode;

                if ($code && $code->kind === TaxKind::Vat) {
                    $isSale = $account->type === AccountType::Revenue;
                    $base = $isSale ? $line->credit - $line->debit : $line->debit - $line->credit;
                    $side = $isSale ? 'sales' : 'purchases';
                    $months[$key][$side][$code->vat_category->value] += $base;
                    $tax = $code->taxOn(abs($base));
                    $months[$key][$isSale ? 'output_vat_from_codes' : 'input_vat_from_codes'] += $base < 0 ? -$tax : $tax;
                }

                $vatRole = match ($account->tax_role) {
                    TaxRole::OutputVat => 'output_vat',
                    TaxRole::InputVat => 'input_vat',
                    default => null,
                };
                if ($vatRole === null) {
                    continue;
                }
                $amount = $vatRole === 'output_vat' ? $line->credit - $line->debit : $line->debit - $line->credit;
                if ($coded) {
                    $months[$key][$vatRole] += $amount;
                } else {
                    $other[] = [
                        'entry_id' => $entry->id,
                        'number' => $entry->number,
                        'entry_date' => $entry->entry_date->format('Y-m-d'),
                        'description' => $entry->description,
                        'account_id' => $account->id,
                        'account_code' => $account->code,
                        'account_name' => $account->name,
                        'role' => $vatRole,
                        'amount' => $amount,
                    ];
                }
            }
        }

        $months = array_values(array_map(fn ($b) => $this->finish($b), $months));
        $total = $this->finish(array_reduce($months, fn ($sum, $b) => $this->add($sum, $b), $this->emptyBucket()));

        return [
            'year' => $year,
            'quarter' => $quarter,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'vat_registered' => Company::current()?->vat_registered,
            'total' => $total,
            'months' => $months,
            'other_vat_activity' => $other,
            'other_vat_activity_total' => [
                'output_vat' => array_sum(array_column(array_filter($other, fn ($o) => $o['role'] === 'output_vat'), 'amount')),
                'input_vat' => array_sum(array_column(array_filter($other, fn ($o) => $o['role'] === 'input_vat'), 'amount')),
            ],
        ];
    }

    private function emptyBucket(): array
    {
        $cats = array_fill_keys(array_map(fn ($c) => $c->value, VatCategory::cases()), 0);

        return [
            'sales' => $cats,
            'purchases' => $cats,
            'output_vat' => 0,
            'input_vat' => 0,
            'output_vat_from_codes' => 0,
            'input_vat_from_codes' => 0,
        ];
    }

    private function add(array $a, array $b): array
    {
        foreach (['sales', 'purchases'] as $side) {
            foreach ($a[$side] as $cat => $v) {
                $a[$side][$cat] = $v + $b[$side][$cat];
            }
        }
        foreach (['output_vat', 'input_vat', 'output_vat_from_codes', 'input_vat_from_codes'] as $k) {
            $a[$k] += $b[$k];
        }

        return $a;
    }

    /** Adds totals and the net: positive is VAT payable, negative is excess input VAT. */
    private function finish(array $b): array
    {
        unset($b['sales']['total'], $b['purchases']['total']);
        $b['sales']['total'] = array_sum($b['sales']);
        $b['purchases']['total'] = array_sum($b['purchases']);
        $b['net_vat_payable'] = $b['output_vat'] - $b['input_vat'];

        return $b;
    }
}
