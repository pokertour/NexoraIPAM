<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasMetadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpAddress extends Model
{
    use Auditable, HasFactory, HasMetadata;

    public const STATUSES = ['used' => 'Attribuée', 'reserved' => 'Réservée', 'available' => 'Disponible'];

    protected $fillable = ['subnet_id', 'address', 'hostname', 'status', 'description'];

    protected static function booted(): void
    {
        static::saving(function (self $address): void {
            $address->address_key = bin2hex(inet_pton($address->address));
        });
    }

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'last_probe_at' => 'datetime', 'last_probe_responded' => 'boolean'];
    }

    /**
     * Filters addresses by the outcome of their last probe, with the same rules
     * as lastPingResponded().
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function wherePing(Builder $query, string $state): Builder
    {
        $answered = 'COALESCE(last_probe_responded, CASE WHEN last_seen_at IS NOT NULL AND last_probe_at = last_seen_at THEN 1 ELSE 0 END)';

        return match ($state) {
            'responding' => $query->whereNotNull('last_probe_at')->whereRaw($answered.' = 1'),
            'unresponsive' => $query->whereNotNull('last_probe_at')->whereRaw($answered.' = 0'),
            default => $query->whereNull('last_probe_at'),
        };
    }

    public function lastPingResponded(): ?bool
    {
        return $this->last_probe_responded ?? ($this->last_probe_at ? ($this->last_seen_at?->equalTo($this->last_probe_at) ?? false) : null);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(AddressObservation::class);
    }

    public function subnet(): BelongsTo
    {
        return $this->belongsTo(Subnet::class);
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['address_key', 'last_probe_at', 'last_seen_at', 'last_probe_responded'];
    }

    public function auditLabel(): string
    {
        return $this->address;
    }
}
