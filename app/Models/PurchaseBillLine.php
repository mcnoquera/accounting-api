<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseBillLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['description', 'amount', 'account_id', 'vat_code_id', 'ewt_code_id', 'position'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'account_id' => 'integer', 'vat_code_id' => 'integer', 'ewt_code_id' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function vatCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'vat_code_id');
    }

    public function ewtCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'ewt_code_id');
    }
}
