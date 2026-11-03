<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYearClose extends Model
{
    use RecordsAudit;

    public const CLOSED = 'closed';

    public const REOPENED = 'reopened';

    protected $fillable = [
        'fiscal_year', 'start_date', 'end_date', 'status', 'retained_earnings_account_id', 'closing_entry_id',
        'net_income', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'net_income' => 'integer',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public function closingEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'closing_entry_id');
    }

    public function retainedEarnings(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'retained_earnings_account_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
