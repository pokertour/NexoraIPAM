<?php

namespace App\Models\Concerns;

use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * Records creations, modifications and deletions in the audit trail.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => Audit::model('created', $model));
        static::updated(fn (Model $model) => Audit::model('updated', $model));
        static::deleted(fn (Model $model) => Audit::model('deleted', $model));
    }

    /**
     * Attributes that change automatically and do not belong in the audit trail.
     *
     * @return list<string>
     */
    public function auditIgnored(): array
    {
        return [];
    }

    public function auditLabel(): string
    {
        return (string) ($this->name ?? '#'.$this->getKey());
    }
}
