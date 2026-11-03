<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = ['account_id', 'debit', 'credit', 'memo', 'vat_code_id', 'ewt_code_id', 'contact_id'];

    protected function casts(): array
    {
        return [
            'debit' => 'integer',
            'credit' => 'integer',
            'vat_code_id' => 'integer',
            'ewt_code_id' => 'integer',
            'contact_id' => 'integer',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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
