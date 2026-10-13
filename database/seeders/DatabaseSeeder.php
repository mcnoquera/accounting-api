<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditFinding;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\JournalEntryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(JournalEntryService $journal): void
    {
        $admin = User::factory()->role(Role::Admin)->create(['name' => 'Alex Admin', 'email' => 'admin@example.com']);
        $accountant = User::factory()->role(Role::Accountant)->create(['name' => 'Casey Accountant', 'email' => 'accountant@example.com']);
        $controller = User::factory()->role(Role::Accountant)->create(['name' => 'Jordan Controller', 'email' => 'controller@example.com']);
        $auditor = User::factory()->role(Role::Auditor)->create(['name' => 'Riley Auditor', 'email' => 'auditor@example.com']);

        Auth::setUser($admin);
        $a = [];
        foreach ([
            ['1000', 'Cash', AccountType::Asset],
            ['1100', 'Accounts Receivable', AccountType::Asset],
            ['1200', 'Inventory', AccountType::Asset],
            ['1500', 'Equipment', AccountType::Asset],
            ['2000', 'Accounts Payable', AccountType::Liability],
            ['2100', 'Accrued Liabilities', AccountType::Liability],
            ['2500', 'Bank Loan', AccountType::Liability],
            ['3000', "Owner's Capital", AccountType::Equity],
            ['3100', 'Retained Earnings', AccountType::Equity],
            ['4000', 'Sales Revenue', AccountType::Revenue],
            ['4100', 'Service Revenue', AccountType::Revenue],
            ['5000', 'Cost of Goods Sold', AccountType::Expense],
            ['6000', 'Salaries Expense', AccountType::Expense],
            ['6100', 'Rent Expense', AccountType::Expense],
            ['6200', 'Utilities Expense', AccountType::Expense],
            ['6300', 'Office Supplies', AccountType::Expense],
        ] as [$code, $name, $type]) {
            $a[$code] = Account::create(['code' => $code, 'name' => $name, 'type' => $type])->id;
        }

        $year = now()->year;
        // [date, description, reference, [[code, debit, credit], ...], preparer, poster|null]
        $entries = [
            ["$year-01-02", 'Owner capital contribution', 'DEP-001', [['1000', '250000.00', 0], ['3000', 0, '250000.00']], $accountant, $controller],
            ["$year-01-05", 'Bank loan proceeds', 'LOAN-2026', [['1000', '100000.00', 0], ['2500', 0, '100000.00']], $accountant, $controller],
            ["$year-01-09", 'Purchase delivery van', 'INV-V-3391', [['1500', '42500.00', 0], ['1000', 0, '42500.00']], $accountant, $controller],
            ["$year-01-15", 'Inventory purchase on account', 'PO-1001', [['1200', '38250.75', 0], ['2000', 0, '38250.75']], $accountant, $controller],
            ["$year-01-30", 'January rent', 'RENT-01', [['6100', '4500.00', 0], ['1000', 0, '4500.00']], $accountant, $controller],
            ["$year-01-30", 'January payroll', 'PR-01', [['6000', '18420.00', 0], ['1000', 0, '18420.00']], $accountant, $controller],
            ["$year-02-03", 'Cash sales week 1', 'POS-0203', [['1000', '12840.50', 0], ['4000', 0, '12840.50']], $accountant, $controller],
            ["$year-02-03", 'COGS for week 1 sales', 'POS-0203', [['5000', '6420.25', 0], ['1200', 0, '6420.25']], $accountant, $controller],
            ["$year-02-12", 'Consulting invoice - Acme Corp', 'INV-2001', [['1100', '9750.00', 0], ['4100', 0, '9750.00']], $accountant, $controller],
            ["$year-02-13", 'Pay supplier', 'CHK-1044', [['2000', '20000.00', 0], ['1000', 0, '20000.00']], $accountant, $controller],
            ["$year-02-27", 'February utilities', 'UTIL-02', [['6200', '812.37', 0], ['1000', 0, '812.37']], $accountant, $controller],
            ["$year-02-27", 'February rent', 'RENT-02', [['6100', '4500.00', 0], ['1000', 0, '4500.00']], $accountant, $controller],
            ["$year-02-27", 'February payroll', 'PR-02', [['6000', '18420.00', 0], ['1000', 0, '18420.00']], $accountant, $controller],
            // Posted 40 days late: backdated-entry flag.
            ["$year-03-06", 'Collect from Acme Corp', 'RCPT-311', [['1000', '9750.00', 0], ['1100', 0, '9750.00']], $accountant, $controller],
            // Self-approved (and weekend-dated in 2026): surfaces in audit exceptions.
            ["$year-03-14", 'Office supplies - urgent', 'CC-5521', [['6300', '1240.18', 0], ['1000', 0, '1240.18']], $accountant, $accountant],
            // Large round amount posted by its preparer: high-severity flag.
            ["$year-03-29", 'Year-end bonus accrual', 'ADJ-07', [['6000', '60000.00', 0], ['2100', 0, '60000.00']], $controller, $controller],
            // Duplicate of the February utilities bill.
            ["$year-02-27", 'February utilities', 'UTIL-02B', [['6200', '812.37', 0], ['1000', 0, '812.37']], $accountant, $controller],
            ["$year-03-20", 'Cash sales March', 'POS-0320', [['1000', '31780.00', 0], ['4000', 0, '31780.00']], $accountant, $controller],
            ["$year-03-20", 'COGS March', 'POS-0320', [['5000', '15890.00', 0], ['1200', 0, '15890.00']], $accountant, $controller],
            // Left as drafts for the review queue.
            ["$year-03-31", 'March rent', 'RENT-03', [['6100', '4500.00', 0], ['1000', 0, '4500.00']], $accountant, null],
            ["$year-03-31", 'March payroll', 'PR-03', [['6000', '18420.00', 0], ['1000', 0, '18420.00']], $accountant, null],
        ];

        foreach ($entries as [$date, $description, $ref, $lines, $preparer, $poster]) {
            Auth::setUser($preparer);
            $entry = $journal->createDraft([
                'entry_date' => $date,
                'reference' => $ref,
                'description' => $description,
                'lines' => array_map(fn ($l) => ['account_id' => $a[$l[0]], 'debit' => $l[1], 'credit' => $l[2]], $lines),
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
