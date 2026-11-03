<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Expanded withholding tax ATCs from the BIR Form 1601-EQ (January 2019 ENCS) schedule,
 * rates per RR 2-98 as amended by RR 11-2018. Codes most businesses use start active; the
 * specialised ones start inactive so pickers stay short (admins can switch them on).
 * Electric-utility meter-deposit codes (WI/WC650-663) are left out.
 *
 * Existing rows only get their name updated: rates are unchanged for the codes seeded
 * earlier, and an admin's active/inactive choice is kept.
 */
return new class extends Migration
{
    private const INDIVIDUAL_TIERS = ['gross income ≤ ₱3M', 'gross income > ₱3M or VAT-registered'];

    private const CORPORATE_TIERS = ['gross income ≤ ₱720K', 'gross income > ₱720K'];

    public function up(): void
    {
        $now = now();
        foreach ($this->codes() as [$code, $name, $rateBp, $active]) {
            $exists = DB::table('tax_codes')->where('code', $code)->exists();
            if ($exists) {
                DB::table('tax_codes')->where('code', $code)->update(['name' => $name, 'updated_at' => $now]);

                continue;
            }
            DB::table('tax_codes')->insert([
                'code' => $code, 'name' => $name, 'kind' => 'ewt', 'rate_bp' => $rateBp, 'vat_category' => null,
                'is_active' => $active, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $original = ['WC158', 'WI158', 'WC160', 'WI160', 'WI010', 'WC010'];
        $added = array_diff(array_column($this->codes(), 0), $original);
        // Codes already used on entries are kept (the foreign key would refuse anyway).
        DB::table('tax_codes')->whereIn('code', $added)
            ->whereNotIn('id', DB::table('journal_lines')->whereNotNull('ewt_code_id')->select('ewt_code_id'))
            ->delete();
    }

    /** @return list<array{0:string, 1:string, 2:int, 3:bool}> [code, name, rate in basis points, active] */
    private function codes(): array
    {
        $rows = [];

        // Fee-based payees: individuals 5% / 10%, corporations 10% / 15% by gross income.
        $tiered = [
            ['010', 'Professional fees (lawyers, CPAs, engineers, etc.)', true],
            ['020', 'Professional entertainers', false],
            ['030', 'Professional athletes', false],
            ['040', 'Directors and producers (movies, stage, radio, TV, music)', false],
            ['050', 'Management and technical consultants', true],
            ['060', 'Business and bookkeeping agents and agencies', true],
            ['070', 'Insurance agents and insurance adjusters', false],
            ['080', 'Other recipients of talent fees', false],
        ];
        foreach ($tiered as [$n, $what, $active]) {
            $next = str_pad((string) ((int) $n + 1), 3, '0', STR_PAD_LEFT);
            $rows[] = ["WI{$n}", "{$what} - individual, ".self::INDIVIDUAL_TIERS[0], 500, $active];
            $rows[] = ["WI{$next}", "{$what} - individual, ".self::INDIVIDUAL_TIERS[1], 1000, $active];
            $rows[] = ["WC{$n}", "{$what} - corporate, ".self::CORPORATE_TIERS[0], 1000, $active];
            $rows[] = ["WC{$next}", "{$what} - corporate, ".self::CORPORATE_TIERS[1], 1500, $active];
        }

        $brokers = 'Commissions of customs, insurance, stock, immigration and commercial brokers, agents of entertainers, real estate service practitioners';
        $salesReps = 'Commissions to independent/exclusive sales representatives and marketing agents';
        $medical = 'Professional fees of medical practitioners paid by hospitals, clinics or HMOs';
        array_push($rows,
            ['WI090', 'Fees of non-employee directors - '.self::INDIVIDUAL_TIERS[0], 500, false],
            ['WI091', 'Fees of non-employee directors - '.self::INDIVIDUAL_TIERS[1], 1000, false],

            ['WI100', 'Rentals (real property used in business, personal property over ₱10K a year, poles, billboards) - individual', 500, true],
            ['WC100', 'Rentals (real property used in business, personal property over ₱10K a year, poles, billboards) - corporate', 500, true],
            ['WI110', 'Cinematographic film rentals - individual', 500, false],
            ['WC110', 'Cinematographic film rentals - corporate', 500, false],
            ['WI120', 'Income payments to certain contractors - individual', 200, true],
            ['WC120', 'Income payments to certain contractors - corporate', 200, true],
            ['WI130', 'Income distribution to beneficiaries of estates and trusts', 1500, false],

            ['WI139', "{$brokers} - individual, ".self::INDIVIDUAL_TIERS[0], 500, true],
            ['WI140', "{$brokers} - individual, ".self::INDIVIDUAL_TIERS[1], 1000, true],
            ['WC139', "{$brokers} - corporate, ".self::CORPORATE_TIERS[0], 1000, true],
            ['WC140', "{$brokers} - corporate, ".self::CORPORATE_TIERS[1], 1500, true],

            ['WI151', "{$medical} - individual, ".self::INDIVIDUAL_TIERS[0], 500, false],
            ['WI150', "{$medical} - individual, ".self::INDIVIDUAL_TIERS[1], 1000, false],
            ['WC151', "{$medical} - corporate, ".self::CORPORATE_TIERS[0], 1000, false],
            ['WC150', "{$medical} - corporate, ".self::CORPORATE_TIERS[1], 1500, false],
            ['WI152', 'Payments by general professional partnerships to partners - '.self::CORPORATE_TIERS[0], 1000, false],
            ['WI153', 'Payments by general professional partnerships to partners - '.self::CORPORATE_TIERS[1], 1500, false],

            ['WI156', 'Income payments made by credit card companies - individual', 50, false],
            ['WC156', 'Income payments made by credit card companies - corporate', 50, false],
            ['WI157', 'Government/GOCC payments to suppliers of services - individual', 200, false],
            ['WC157', 'Government/GOCC payments to suppliers of services - corporate', 200, false],
            ['WI158', 'Top withholding agent payments to suppliers of goods - individual', 100, true],
            ['WC158', 'Top withholding agent payments to suppliers of goods - corporate', 100, true],
            ['WI159', 'Additional payments to government personnel for overtime (importers, shipping, airlines)', 1500, false],
            ['WI160', 'Top withholding agent payments to suppliers of services - individual', 200, true],
            ['WC160', 'Top withholding agent payments to suppliers of services - corporate', 200, true],

            ['WI515', "{$salesReps} - individual, ".self::INDIVIDUAL_TIERS[0], 500, true],
            ['WI516', "{$salesReps} - individual, ".self::INDIVIDUAL_TIERS[1], 1000, true],
            ['WC515', "{$salesReps} - corporate, ".self::CORPORATE_TIERS[0], 1000, true],
            ['WC516', "{$salesReps} - corporate, ".self::CORPORATE_TIERS[1], 1500, true],

            ['WI530', 'Gross payments to embalmers by funeral parlors', 100, false],
            ['WI535', 'Payments by pre-need companies to funeral parlors - individual', 100, false],
            ['WC535', 'Payments by pre-need companies to funeral parlors - corporate', 100, false],
            ['WI540', 'Tolling fees paid to refineries - individual', 500, false],
            ['WC540', 'Tolling fees paid to refineries - corporate', 500, false],
            ['WI610', 'Purchases of agricultural products over ₱300K cumulative in the year - individual', 100, false],
            ['WC610', 'Purchases of agricultural products over ₱300K cumulative in the year - corporate', 100, false],
            ['WI630', 'Purchases of minerals, mineral products and quarry resources - individual', 500, false],
            ['WC630', 'Purchases of minerals, mineral products and quarry resources - corporate', 500, false],
            ['WI632', 'BSP purchases of gold from miners/suppliers - individual', 100, false],
            ['WC632', 'BSP purchases of gold from miners/suppliers - corporate', 100, false],
            ['WI640', 'Government/GOCC payments to suppliers of goods - individual', 100, false],
            ['WC640', 'Government/GOCC payments to suppliers of goods - corporate', 100, false],
            ['WI680', 'Purchases by political parties and candidates for campaign expenditures - individual', 500, false],
            ['WC680', 'Purchases by political parties and candidates for campaign expenditures - corporate', 500, false],
            ['WC690', 'Income payments received by real estate investment trusts (REITs)', 100, false],
            ['WI710', 'Interest on other debt instruments not covered as deposit substitutes - individual', 1500, false],
            ['WC710', 'Interest on other debt instruments not covered as deposit substitutes - corporate', 1500, false],
            ['WI720', 'Purchases of locally produced raw sugar - individual', 100, false],
            ['WC720', 'Purchases of locally produced raw sugar - corporate', 100, false],
        );

        return $rows;
    }
};
