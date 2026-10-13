<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an audit log row for every create, update and delete on the model.
 */
trait RecordsAudit
{
    public static function bootRecordsAudit(): void
    {
        static::created(function (Model $model) {
            app(AuditLogger::class)->record('created', $model, null, $model->auditableAttributes());
        });

        static::updated(function (Model $model) {
            $changed = array_keys($model->getChanges());
            $changed = array_values(array_diff($changed, ['updated_at']));
            if ($changed === []) {
                return;
            }
            $old = array_intersect_key($model->getRawOriginal(), array_flip($changed));
            $new = array_intersect_key($model->getAttributes(), array_flip($changed));
            app(AuditLogger::class)->record('updated', $model, $old, $new);
        });

        static::deleted(function (Model $model) {
            app(AuditLogger::class)->record('deleted', $model, $model->auditableAttributes(), null);
        });
    }

    /** @return array<string, mixed> */
    public function auditableAttributes(): array
    {
        return array_diff_key($this->getAttributes(), array_flip(['created_at', 'updated_at']));
    }
}
