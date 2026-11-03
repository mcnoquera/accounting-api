<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    protected $fillable = ['document_type', 'document_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'document_id' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** The invoice or bill this allocation settles. */
    public function document(): SalesInvoice|PurchaseBill|null
    {
        return $this->document_type === 'sales_invoice'
            ? SalesInvoice::query()->find($this->document_id)
            : PurchaseBill::query()->find($this->document_id);
    }
}
