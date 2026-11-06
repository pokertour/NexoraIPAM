<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMetadata;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vlan extends Model
{
    use Auditable, HasFactory, HasMetadata;

    protected $fillable = ['number', 'name', 'description', 'location_id'];

    protected static function booted(): void
    {
        static::saving(function (self $vlan): void {
            $vlan->scope_id = $vlan->location_id ?? 0;
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['scope_id'];
    }

    public function auditLabel(): string
    {
        return $this->number.' · '.$this->name;
    }
}
