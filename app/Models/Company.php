<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use RecordsAudit;

    protected $fillable = [
        'name', 'trade_name', 'tin', 'rdo_code', 'address', 'zip_code',
        'vat_registered', 'currency', 'fiscal_year_start_month',
    ];

    protected function casts(): array
    {
        return [
            'vat_registered' => 'boolean',
            'fiscal_year_start_month' => 'integer',
        ];
    }

    /** The one company for this installation, or null before first-run setup. */
    public static function current(): ?self
    {
        return static::query()->orderBy('id')->first();
    }
}
