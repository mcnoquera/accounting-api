<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\CashKind;
use App\Enums\ControlType;
use App\Enums\TaxRole;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory, RecordsAudit;

    protected $fillable = ['code', 'name', 'type', 'parent_id', 'tax_role', 'control_type', 'is_cash', 'cash_kind', 'provider', 'account_number_last4', 'description', 'is_active'];

    protected $appends = ['normal_balance'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'tax_role' => TaxRole::class,
            'control_type' => ControlType::class,
            'is_cash' => 'boolean',
            'cash_kind' => CashKind::class,
            'parent_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** "debit" for assets and expenses, "credit" for the rest. */
    public function getNormalBalanceAttribute(): ?string
    {
        if (! $this->type instanceof AccountType) {
            return null;
        }

        return $this->type->isDebitNormal() ? 'debit' : 'credit';
    }
}
