<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier's bill. "issued" means recorded in the books. */
class PurchaseBill extends Model
{
    use RecordsAudit;

    protected $fillable = [
        'number', 'contact_id', 'supplier_invoice_number', 'bill_date', 'due_date', 'notes', 'status', 'is_opening',
        'net_amount', 'vat_amount', 'ewt_amount', 'total_amount', 'amount_due', 'amount_paid', 'journal_entry_id',
        'created_by', 'issued_by', 'issued_at', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected $appends = ['balance_due'];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'status' => InvoiceStatus::class,
            'is_opening' => 'boolean',
            'net_amount' => 'integer',
            'vat_amount' => 'integer',
            'ewt_amount' => 'integer',
            'total_amount' => 'integer',
            'amount_due' => 'integer',
            'amount_paid' => 'integer',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getBalanceDueAttribute(): int
    {
        return $this->status?->isOpen() ? $this->amount_due - $this->amount_paid : 0;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseBillLine::class)->orderBy('position');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
