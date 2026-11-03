<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\EntryStatus;
use App\Enums\TaxKind;
use App\Enums\VatCategory;
use App\Models\Company;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;

/**
 * Quarterly percentage tax (supports BIR Form 2551Q) for non-VAT businesses, for a calendar
 * quarter: gross sales/receipts from revenue lines of posted entries (returns and reversals
 * reduce them; year-end closing entries are left out), less VAT-exempt sales, times the
 * company's percentage tax rate. Amounts are cents.
 */
class PercentageTaxService
{
    public function summary(int $year, int $quarter): array
    {
        $from = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1);
        $to = $from->addMonths(2)->endOfMonth();
        $company = Company::current();
        $rateBp = $company?->percentage_tax_rate_bp ?? 300;

        $months = [];
        for ($m = $from; $m <= $to; $m = $m->addMonth()) {
            $months[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'gross' => 0, 'exempt' => 0];
        }

        $entries = JournalEntry::query()
            ->with(['lines.account', 'lines.vatCode'])
            ->where('status', EntryStatus::Posted)
            ->where('is_closing', false)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        foreach ($entries as $entry) {
            $key = $entry->entry_date->format('Y-m');
            foreach ($entry->lines as $line) {
                if ($line->account->type !== AccountType::Revenue) {
                    continue;
                }
                $amount = $line->credit - $line->debit;
                $months[$key]['gross'] += $amount;
                if ($line->vatCode?->kind === TaxKind::Vat && $line->vatCode->vat_category === VatCategory::Exempt) {
                    $months[$key]['exempt'] += $amount;
                }
            }
        }

        $tax = fn (int $taxable) => ($taxable < 0 ? -1 : 1) * intdiv(abs($taxable) * $rateBp + 5000, 10000);
        $months = array_values(array_map(function ($m) use ($tax) {
            $m['taxable'] = $m['gross'] - $m['exempt'];
            $m['tax'] = $tax($m['taxable']);

            return $m;
        }, $months));

        $gross = array_sum(array_column($months, 'gross'));
        $exempt = array_sum(array_column($months, 'exempt'));

        return [
            'year' => $year,
            'quarter' => $quarter,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'vat_registered' => $company?->vat_registered,
            'rate_bp' => $rateBp,
            'rate' => number_format($rateBp / 100, 2, '.', ''),
            'months' => $months,
            // The quarter's tax is computed on the quarter's total, so it can differ from the
            // sum of the monthly figures by a centavo.
            'total' => ['gross' => $gross, 'exempt' => $exempt, 'taxable' => $gross - $exempt, 'tax' => $tax($gross - $exempt)],
        ];
    }
}
