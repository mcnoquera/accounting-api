<?php

namespace App\Support;

use App\Enums\AccountType;

/**
 * Starter chart of accounts for a Philippine small business (VAT, withholding taxes,
 * SSS / PhilHealth / Pag-IBIG). Group accounts (null parent) hold the detail accounts
 * that entries post to. Have your external accountant review it before go-live.
 */
final class DefaultChartOfAccounts
{
    /** @return list<array{0:string, 1:string, 2:AccountType, 3:?string}> [code, name, type, parent code] */
    public static function rows(): array
    {
        $A = AccountType::Asset;
        $L = AccountType::Liability;
        $E = AccountType::Equity;
        $R = AccountType::Revenue;
        $X = AccountType::Expense;

        return [
            ['1100', 'Cash and Cash Equivalents', $A, null],
            ['1110', 'Cash on Hand', $A, '1100'],
            ['1120', 'Petty Cash Fund', $A, '1100'],
            ['1130', 'Cash in Bank - Current Account', $A, '1100'],
            ['1140', 'Cash in Bank - Savings Account', $A, '1100'],
            ['1200', 'Receivables', $A, null],
            ['1210', 'Accounts Receivable - Trade', $A, '1200'],
            ['1220', 'Allowance for Doubtful Accounts', $A, '1200'],
            ['1230', 'Advances to Officers and Employees', $A, '1200'],
            ['1240', 'Other Receivables', $A, '1200'],
            ['1300', 'Inventories', $A, null],
            ['1310', 'Merchandise Inventory', $A, '1300'],
            ['1320', 'Supplies Inventory', $A, '1300'],
            ['1400', 'Prepayments and Other Current Assets', $A, null],
            ['1410', 'Prepaid Expenses', $A, '1400'],
            ['1420', 'Input VAT', $A, '1400'],
            ['1430', 'Creditable Withholding Tax', $A, '1400'],
            ['1440', 'Prepaid Income Tax', $A, '1400'],
            ['1500', 'Property and Equipment', $A, null],
            ['1510', 'Land', $A, '1500'],
            ['1520', 'Building', $A, '1500'],
            ['1530', 'Furniture and Fixtures', $A, '1500'],
            ['1540', 'Office and Computer Equipment', $A, '1500'],
            ['1550', 'Transportation Equipment', $A, '1500'],
            ['1590', 'Accumulated Depreciation', $A, '1500'],
            ['1600', 'Other Non-current Assets', $A, null],
            ['1610', 'Security Deposits', $A, '1600'],

            ['2100', 'Trade and Other Payables', $L, null],
            ['2110', 'Accounts Payable - Trade', $L, '2100'],
            ['2120', 'Accrued Expenses', $L, '2100'],
            ['2130', 'Advances from Customers', $L, '2100'],
            ['2200', 'Taxes Payable', $L, null],
            ['2210', 'Output VAT', $L, '2200'],
            ['2220', 'VAT Payable', $L, '2200'],
            ['2230', 'Withholding Tax Payable - Expanded', $L, '2200'],
            ['2240', 'Withholding Tax Payable - Compensation', $L, '2200'],
            ['2250', 'Income Tax Payable', $L, '2200'],
            ['2300', 'Statutory Contributions Payable', $L, null],
            ['2310', 'SSS Contributions Payable', $L, '2300'],
            ['2320', 'PhilHealth Contributions Payable', $L, '2300'],
            ['2330', 'Pag-IBIG Contributions Payable', $L, '2300'],
            ['2500', 'Non-current Liabilities', $L, null],
            ['2510', 'Loans Payable', $L, '2500'],

            ['3000', 'Equity', $E, null],
            ['3100', "Owner's Capital / Share Capital", $E, '3000'],
            ['3200', 'Retained Earnings', $E, '3000'],
            ['3300', "Owner's Drawings / Dividends", $E, '3000'],

            ['4000', 'Revenue', $R, null],
            ['4100', 'Sales', $R, '4000'],
            ['4200', 'Service Income', $R, '4000'],
            ['4300', 'Sales Returns and Discounts', $R, '4000'],
            ['4900', 'Other Income', $R, null],
            ['4910', 'Interest Income', $R, '4900'],
            ['4920', 'Miscellaneous Income', $R, '4900'],

            ['5000', 'Cost of Sales', $X, null],
            ['5100', 'Cost of Goods Sold', $X, '5000'],
            ['5200', 'Freight-in', $X, '5000'],
            ['6000', 'Operating Expenses', $X, null],
            ['6010', 'Salaries and Wages', $X, '6000'],
            ['6020', 'SSS, PhilHealth and Pag-IBIG Contributions', $X, '6000'],
            ['6030', '13th Month Pay and Other Benefits', $X, '6000'],
            ['6040', 'Rent Expense', $X, '6000'],
            ['6050', 'Light and Water', $X, '6000'],
            ['6060', 'Communication Expense', $X, '6000'],
            ['6070', 'Office Supplies Expense', $X, '6000'],
            ['6080', 'Transportation and Travel', $X, '6000'],
            ['6090', 'Repairs and Maintenance', $X, '6000'],
            ['6100', 'Depreciation Expense', $X, '6000'],
            ['6110', 'Taxes and Licenses', $X, '6000'],
            ['6120', 'Professional Fees', $X, '6000'],
            ['6130', 'Representation and Entertainment', $X, '6000'],
            ['6140', 'Insurance Expense', $X, '6000'],
            ['6150', 'Advertising and Promotion', $X, '6000'],
            ['6160', 'Bank Charges', $X, '6000'],
            ['6170', 'Bad Debts Expense', $X, '6000'],
            ['6900', 'Miscellaneous Expense', $X, '6000'],
            ['7000', 'Income Tax Expense', $X, null],
            ['7100', 'Provision for Income Tax', $X, '7000'],
        ];
    }
}
