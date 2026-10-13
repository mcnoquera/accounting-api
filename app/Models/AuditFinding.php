<?php

namespace App\Models;

use App\Enums\FindingStatus;
use App\Enums\Severity;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditFinding extends Model
{
    use RecordsAudit;

    protected $fillable = [
        'journal_entry_id', 'title', 'description', 'severity', 'status',
        'raised_by', 'resolved_by', 'resolved_at', 'resolution',
    ];

    protected function casts(): array
    {
        return [
            'severity' => Severity::class,
            'status' => FindingStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
