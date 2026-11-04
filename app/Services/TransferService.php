<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Exceptions\DomainRuleException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\User;

/**
 * Moves money between two cash accounts (e.g. GCash cash-out to a bank, bank to petty cash),
 * with an optional fee, as an ordinary posted entry: Dr destination, Dr fee expense,
 * Cr source. Transfers aren't income or expenses, so they stay out of sales and purchases.
 */
class TransferService
{
    public function __construct(private JournalEntryService $journal) {}

    public function record(array $data, User $user): JournalEntry
    {
        $from = Account::query()->find($data['from_account_id']);
        $to = Account::query()->find($data['to_account_id']);
        foreach (['from_account_id' => $from, 'to_account_id' => $to] as $field => $a) {
            if (! $a || ! $a->is_cash || ! $a->is_active) {
                throw new DomainRuleException('Transfers are between active cash, bank or e-wallet accounts.', [$field => ['Choose a cash, bank or e-wallet account.']]);
            }
        }
        if ($from->id === $to->id) {
            throw new DomainRuleException('Choose two different accounts.', ['to_account_id' => ['Must differ from the source.']]);
        }

        $amount = JournalEntryService::toCents($data['amount']);
        $fee = JournalEntryService::toCents($data['fee'] ?? 0);
        if ($amount === 0) {
            throw new DomainRuleException('Enter the amount transferred.', ['amount' => ['Enter an amount above zero.']]);
        }
        $feeAccount = null;
        if ($fee > 0) {
            $feeAccount = Account::query()->find($data['fee_account_id'] ?? 0);
            if (! $feeAccount || $feeAccount->type !== AccountType::Expense || ! $feeAccount->is_active) {
                throw new DomainRuleException('Choose the expense account for the fee (e.g. Bank Charges).', ['fee_account_id' => ['Choose an expense account.']]);
            }
        }

        $pesos = fn (int $c) => intdiv($c, 100).'.'.str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
        $lines = [['account_id' => $to->id, 'debit' => $pesos($amount), 'credit' => '0', 'memo' => "From {$from->name}"]];
        if ($feeAccount) {
            $lines[] = ['account_id' => $feeAccount->id, 'debit' => $pesos($fee), 'credit' => '0', 'memo' => 'Transfer fee'];
        }
        $lines[] = ['account_id' => $from->id, 'debit' => '0', 'credit' => $pesos($amount + $fee), 'memo' => "To {$to->name}"];

        $entry = $this->journal->createDraft([
            'entry_date' => $data['date'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'] ?? "Transfer from {$from->name} to {$to->name}",
            'lines' => $lines,
        ], $user);

        return $this->journal->post($entry, $user);
    }
}
