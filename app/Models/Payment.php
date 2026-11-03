<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer receipt or a supplier payment, allocated to invoices or bills. */
class Payment extends Model
{
    use RecordsAudit;

    public const RECEIPT = 'receipt';

    public const PAYMENT = 'payment';

    protected $fillable = [
        'number', 'direction', 'contact_id', 'payment_date', 'cash_account_id', 'amount', 'ewt_code_id',
        'withholding_base', 'withholding_amount', 'reference', 'notes', 'status', 'journal_entry_id',
        'created_by', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected $appends = ['applied_amount', 'unapplied_amount'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'amount' => 'integer',
            'withholding_base' => 'integer',
            'withholding_amount' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    /** What the payment settles: cash plus any tax the customer withheld. */
    public function settles(): int
    {
        return $this->amount + $this->withholding_amount;
    }

    public function getAppliedAmountAttribute(): int
    {
        return (int) ($this->relationLoaded('allocations') ? $this->allocations->sum('amount') : $this->allocations()->sum('amount'));
    }

    public function getUnappliedAmountAttribute(): int
    {
        return $this->status === 'voided' ? 0 : $this->settles() - $this->applied_amount;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function ewtCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'ewt_code_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
