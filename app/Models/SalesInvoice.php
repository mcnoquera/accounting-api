<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesInvoice extends Model
{
    use RecordsAudit;

    protected $fillable = [
        'number', 'contact_id', 'invoice_date', 'due_date', 'reference', 'notes', 'status',
        'net_amount', 'vat_amount', 'total_amount', 'amount_paid', 'journal_entry_id',
        'created_by', 'issued_by', 'issued_at', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected $appends = ['balance_due'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'status' => InvoiceStatus::class,
            'net_amount' => 'integer',
            'vat_amount' => 'integer',
            'total_amount' => 'integer',
            'amount_paid' => 'integer',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getBalanceDueAttribute(): int
    {
        return $this->status?->isOpen() ? $this->total_amount - $this->amount_paid : 0;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('position');
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
