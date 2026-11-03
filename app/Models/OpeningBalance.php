<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalance extends Model
{
    use RecordsAudit;

    protected $fillable = ['cutover_date', 'journal_entry_id', 'equity_account_id', 'created_by'];

    protected function casts(): array
    {
        return ['cutover_date' => 'date:Y-m-d'];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function equityAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'equity_account_id');
    }
}
