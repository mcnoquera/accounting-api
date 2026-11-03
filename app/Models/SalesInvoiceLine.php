<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['description', 'quantity_milli', 'unit_price', 'amount', 'account_id', 'vat_code_id', 'position'];

    protected $appends = ['quantity'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
            'account_id' => 'integer',
            'vat_code_id' => 'integer',
        ];
    }

    /** "1.5" for 1500; trailing zeros trimmed. */
    public function getQuantityAttribute(): string
    {
        return rtrim(rtrim(number_format($this->quantity_milli / 1000, 3, '.', ''), '0'), '.');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function vatCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'vat_code_id');
    }
}
