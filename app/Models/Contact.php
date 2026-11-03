<?php

namespace App\Models;

use App\Enums\ContactType;
use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer and/or supplier that receivable and payable lines are tracked against. */
class Contact extends Model
{
    use HasFactory, RecordsAudit;

    protected $fillable = ['name', 'type', 'tin', 'address', 'email', 'phone', 'payment_terms_days', 'is_active'];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
