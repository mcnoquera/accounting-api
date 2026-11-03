<?php

namespace Database\Seeders;

use App\Enums\ContactType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditFinding;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\AccountService;
use App\Services\JournalEntryService;
use App\Services\PeriodService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Demo books for a fictional VAT-registered trading company on the Philippine starter chart:
 * VAT and withholding on sales and purchases, customers and suppliers on the control
 * accounts, open receivables for aging, two closed months, and entries that trip the audit
 * exception tests. Every name, TIN and address here is made up.
 */
class DatabaseSeeder extends Seeder
{
    public function run(JournalEntryService $journal, AccountService $accounts, PeriodService $periods): void
    {
        // Demo data assumes an empty database; never mix it into real books.
        if (User::exists()) {
            $this->command?->warn('Users already exist; skipping demo seed.');

            return;
        }

        $admin = User::factory()->role(Role::Admin)->create(['name' => 'Alex Admin', 'email' => 'admin@example.com']);
        $accountant = User::factory()->role(Role::Accountant)->create(['name' => 'Casey Accountant', 'email' => 'accountant@example.com']);
        $controller = User::factory()->role(Role::Accountant)->create(['name' => 'Jordan Controller', 'email' => 'controller@example.com']);
        $auditor = User::factory()->role(Role::Auditor)->create(['name' => 'Riley Auditor', 'email' => 'auditor@example.com']);

        Auth::setUser($admin);
        Company::create([
            'name' => 'Bayanihan Trading Corporation (Demo)',
            'trade_name' => 'Bayanihan Trading',
            'tin' => '123-456-789-000',
            'rdo_code' => '050',
            'address' => '123 Demo Street, Barangay Sample, Makati City, Metro Manila',
            'zip_code' => '1200',
            'vat_registered' => true,
            'currency' => 'PHP',
            'fiscal_year_start_month' => 1,
        ]);
        $accounts->loadDefaults();
        $acct = Account::query()->pluck('id', 'code');
        $code = TaxCode::query()->pluck('id', 'code');

        $c = [];
        foreach ([
            'acme' => ['Acme Consulting Clients Inc. (Demo)', ContactType::Customer, '200-111-222-000', 30],
            'mabuhay' => ['Mabuhay Retail Stores Inc. (Demo)', ContactType::Customer, '200-333-444-000', 30],
            'pacific' => ['Pacific Export Partners Ltd. (Demo)', ContactType::Customer, null, 60],
            'visayas' => ['Visayas Wholesale Supply Co. (Demo)', ContactType::Supplier, '300-111-222-000', 30],
            'landlord' => ['Makati Property Holdings Inc. (Demo)', ContactType::Supplier, '300-333-444-000', 0],
            'santos' => ['Atty. Maria Santos (Demo)', ContactType::Supplier, '400-555-666-000', 15],
        ] as $key => [$name, $type, $tin, $terms]) {
            $c[$key] = Contact::create(['name' => $name, 'type' => $type, 'tin' => $tin, 'payment_terms_days' => $terms])->id;
        }

        $year = now()->year;
        // [date, description, reference, lines [[account code, debit, credit, VAT code?, EWT code?, contact?]], preparer, poster|null]
        $rent = fn (string $m) => [['6040', '45000.00', 0, 'VAT12', 'WC100', 'landlord'], ['1420', '5400.00', 0], ['2230', 0, '2250.00'], ['1130', 0, '48150.00']];
        $payroll = [['6010', '18420.00', 0], ['1130', 0, '18420.00']];
        $utilities = [['6050', '812.37', 0, 'VAT12'], ['1420', '97.48', 0], ['1130', 0, '909.85']];
        $entries = [
            ["$year-01-02", 'Owner capital contribution', 'DEP-001', [['1130', '250000.00', 0], ['3100', 0, '250000.00']], $accountant, $controller],
            ["$year-01-05", 'Bank loan proceeds', 'LOAN-2026', [['1130', '100000.00', 0], ['2510', 0, '100000.00']], $accountant, $controller],
            ["$year-01-09", 'Purchase delivery van', 'INV-V-3391', [['1550', '42500.00', 0, 'VAT12'], ['1420', '5100.00', 0], ['1130', 0, '47600.00']], $accountant, $controller],
            ["$year-01-15", 'Merchandise purchase on account', 'PO-1001', [['1310', '38250.75', 0, 'VAT12'], ['1420', '4590.09', 0], ['2110', 0, '42840.84', null, null, 'visayas']], $accountant, $controller],
            ["$year-01-30", 'January rent (5% withheld)', 'RENT-01', $rent('01'), $accountant, $controller],
            ["$year-01-30", 'January payroll', 'PR-01', $payroll, $accountant, $controller],
            ["$year-02-03", 'Cash sales week 1', 'POS-0203', [['1110', '12840.50', 0], ['4100', 0, '11464.73', 'VAT12'], ['2210', 0, '1375.77']], $accountant, $controller],
            ["$year-02-03", 'Cost of goods sold, week 1', 'POS-0203', [['5100', '6420.25', 0], ['1310', 0, '6420.25']], $accountant, $controller],
            // Client withholds 2% (WC160); the 2307 credit is booked with the invoice.
            ["$year-02-12", 'Consulting services - Acme', 'INV-2001', [['1210', '10725.00', 0, null, null, 'acme'], ['1430', '195.00', 0], ['4200', 0, '9750.00', 'VAT12', 'WC160'], ['2210', 0, '1170.00']], $accountant, $controller],
            ["$year-02-13", 'Payment to Visayas Wholesale', 'CHK-1044', [['2110', '20000.00', 0, null, null, 'visayas'], ['1130', 0, '20000.00']], $accountant, $controller],
            ["$year-02-27", 'February light and water', 'UTIL-02', $utilities, $accountant, $controller],
            ["$year-02-27", 'February rent (5% withheld)', 'RENT-02', $rent('02'), $accountant, $controller],
            ["$year-02-27", 'February payroll', 'PR-02', $payroll, $accountant, $controller],
            // Duplicate of the February utilities bill: audit exception.
            ["$year-02-27", 'February light and water', 'UTIL-02B', $utilities, $accountant, $controller],
            // Posted 40 days late: backdated-entry flag.
            ["$year-03-06", 'Collection from Acme', 'RCPT-311', [['1130', '10725.00', 0], ['1210', 0, '10725.00', null, null, 'acme']], $accountant, $controller],
            ["$year-03-10", 'Sale on account - Mabuhay Retail', 'SI-0310', [['1210', '56000.00', 0, null, null, 'mabuhay'], ['4100', 0, '50000.00', 'VAT12'], ['2210', 0, '6000.00']], $accountant, $controller],
            // Self-approved and dated on a weekend: audit exceptions.
            ["$year-03-14", 'Office supplies - urgent', 'CC-5521', [['6070', '1240.18', 0, 'VAT12'], ['1420', '148.82', 0], ['1110', 0, '1389.00']], $accountant, $accountant],
            ["$year-03-18", 'Export sale - Pacific (zero-rated)', 'SI-0318', [['1210', '15000.00', 0, null, null, 'pacific'], ['4100', 0, '15000.00', 'VAT0']], $accountant, $controller],
            ["$year-03-20", 'Cash sales March', 'POS-0320', [['1110', '31780.00', 0], ['4100', 0, '28375.00', 'VAT12'], ['2210', 0, '3405.00']], $accountant, $controller],
            ["$year-03-20", 'Cost of goods sold, March', 'POS-0320', [['5100', '15890.00', 0], ['1310', 0, '15890.00']], $accountant, $controller],
            // Non-VAT lawyer, 5% withheld (WI010).
            ["$year-03-25", 'Legal fees - Atty. Santos', 'PF-0325', [['6120', '20000.00', 0, 'VATEX', 'WI010'], ['2230', 0, '1000.00'], ['2110', 0, '19000.00', null, null, 'santos']], $accountant, $controller],
            // Large round amount posted by its preparer: high-severity flag.
            ["$year-03-29", 'Year-end bonus accrual', 'ADJ-07', [['6030', '60000.00', 0], ['2120', 0, '60000.00']], $controller, $controller],
            // Left as drafts for the review queue.
            ["$year-03-31", 'March rent (5% withheld)', 'RENT-03', $rent('03'), $accountant, null],
            ["$year-03-31", 'March payroll', 'PR-03', $payroll, $accountant, null],
        ];

        foreach ($entries as [$date, $description, $ref, $lines, $preparer, $poster]) {
            Auth::setUser($preparer);
            $entry = $journal->createDraft([
                'entry_date' => $date,
                'reference' => $ref,
                'description' => $description,
                'lines' => array_map(fn ($l) => [
                    'account_id' => $acct[$l[0]],
                    'debit' => $l[1],
                    'credit' => $l[2],
                    'vat_code_id' => isset($l[3]) ? $code[$l[3]] : null,
                    'ewt_code_id' => isset($l[4]) ? $code[$l[4]] : null,
                    'contact_id' => isset($l[5]) ? $c[$l[5]] : null,
                ], $lines),
            ], $preparer);

            if ($poster) {
                Auth::setUser($poster);
                $journal->post($entry, $poster);
                // Backdate the posting timestamp so the demo reads like real history.
                $lag = $ref === 'RCPT-311' ? 40 : 1;
                DB::table('journal_entries')->where('id', $entry->id)
                    ->update(['posted_at' => Carbon::parse($date)->addDays($lag)->setTime(10, 0)]);
            }
        }

        // January and February are closed, as after a normal month-end.
        Auth::setUser($controller);
        foreach (['01', '02'] as $m) {
            $periods->close("$year-$m", $controller);
        }

        Auth::setUser($auditor);
        AuditFinding::create([
            'title' => 'Year-end bonus accrual lacks approval memo',
            'description' => 'ADJ-07 accrues 60,000.00 in bonuses with no board approval attached. Provide supporting documentation.',
            'severity' => 'high',
            'status' => 'open',
            'raised_by' => $auditor->id,
            'journal_entry_id' => JournalEntry::where('reference', 'ADJ-07')->value('id'),
        ]);
    }
}
