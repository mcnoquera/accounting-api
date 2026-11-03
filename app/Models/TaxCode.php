<?php

namespace App\Models;

use App\Enums\TaxKind;
use App\Enums\VatCategory;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxCode extends Model
{
    use RecordsAudit;

    protected $fillable = ['code', 'name', 'kind', 'rate_bp', 'vat_category', 'is_active'];

    protected $appends = ['rate'];

    protected function casts(): array
    {
        return [
            'kind' => TaxKind::class,
            'vat_category' => VatCategory::class,
            'rate_bp' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function vatLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'vat_code_id');
    }

    public function ewtLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'ewt_code_id');
    }

    /** Whether any journal line carries this code. */
    public function isUsed(): bool
    {
        return JournalLine::query()->where('vat_code_id', $this->id)->orWhere('ewt_code_id', $this->id)->exists();
    }

    /** Rate as a percentage string, e.g. "12.00". */
    public function getRateAttribute(): string
    {
        return number_format($this->rate_bp / 100, 2, '.', '');
    }

    /** Tax on a base amount in cents, rounded half up. */
    public function taxOn(int $baseCents): int
    {
        return intdiv($baseCents * $this->rate_bp + 5000, 10000);
    }
}
