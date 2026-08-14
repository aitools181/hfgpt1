<?php

namespace App\Concerns;

use App\Services\AuditTrail;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(fn (Model $model) => app(AuditTrail::class)->recordModelChange($model, 'created', [], $model->getAttributes()));
        static::updated(fn (Model $model) => app(AuditTrail::class)->recordModelChange($model, 'updated', $model->getOriginal(), $model->getChanges()));
        static::deleted(fn (Model $model) => app(AuditTrail::class)->recordModelChange($model, 'deleted', $model->getOriginal(), []));
    }
}
