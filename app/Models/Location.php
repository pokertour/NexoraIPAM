<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use Auditable, HasFactory;

    public const KINDS = ['agency' => 'Agence', 'office' => 'Bureau', 'floor' => 'Étage'];

    public const PARENT_KINDS = ['agency' => null, 'office' => 'agency', 'floor' => 'office'];

    protected $fillable = ['name', 'kind', 'parent_id', 'description'];

    protected function casts(): array
    {
        return ['discovery_interval' => 'integer', 'next_discovery_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function subnets(): HasMany
    {
        return $this->hasMany(Subnet::class);
    }

    public function vlans(): HasMany
    {
        return $this->hasMany(Vlan::class);
    }

    public function path(): string
    {
        return $this->parent ? $this->parent->path().' / '.$this->name : $this->name;
    }

    /** @return list<int> */
    public function descendantsAndSelf(): array
    {
        $children = $this->children()->with('children')->get();

        return [$this->id, ...$children->modelKeys(), ...$children->flatMap(fn (self $child): array => $child->children->modelKeys())->all()];
    }

    public function belongsToLocation(int $id): bool
    {
        return $this->id === $id || ($this->parent?->belongsToLocation($id) ?? false);
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['next_discovery_at'];
    }

    public function auditLabel(): string
    {
        return $this->path();
    }
}
