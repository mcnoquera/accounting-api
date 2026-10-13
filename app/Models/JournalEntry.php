<?php

namespace App\Models;

use App\Enums\EntryStatus;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasFactory, RecordsAudit;

    protected $fillable = [
        'number', 'entry_date', 'reference', 'description', 'status',
        'created_by', 'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'status' => EntryStatus::class,
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AuditFinding::class);
    }

    public function totalDebit(): int
    {
        return (int) $this->lines->sum('debit');
    }

    public function totalCredit(): int
    {
        return (int) $this->lines->sum('credit');
    }
}
