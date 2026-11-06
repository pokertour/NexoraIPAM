<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\IpNetwork;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subnet extends Model
{
    use Auditable, HasFactory;

    protected $fillable = ['cidr', 'name', 'vlan_id', 'description', 'location_id'];

    protected static function booted(): void
    {
        static::saving(function (self $subnet): void {
            $subnet->scope_id = $subnet->location_id ?? 0;
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function vlan(): BelongsTo
    {
        return $this->belongsTo(Vlan::class);
    }

    protected function casts(): array
    {
        return ['scan_requested_at' => 'datetime', 'scanned_at' => 'datetime'];
    }

    public function scanPending(): bool
    {
        return $this->scan_status === 'queued' || ($this->scan_status === 'running'
            && $this->updated_at?->isAfter(now()->subMinutes(15)));
    }

    public function ipv4Capacity(): int
    {
        $bounds = IpNetwork::details($this->cidr);
        $first = ip2long($bounds['first']);
        $last = ip2long($bounds['last']);
        $capacity = $last - $first + 1;

        foreach ($this->childSubnets as $child) {
            $range = IpNetwork::details($child->cidr);
            $start = ip2long(explode('/', $child->cidr)[0]);
            $end = ip2long($range['broadcast'] ?? $range['last']);
            $capacity -= max(0, min($last, $end) - max($first, $start) + 1);
        }

        return $capacity;
    }

    public function parentSubnet(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    public function childSubnets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_subnet_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return ['scope_id', 'scan_token', 'scan_status', 'scan_requested_at', 'scanned_at', 'scan_targets', 'scan_responded'];
    }

    public function auditLabel(): string
    {
        return $this->name.' · '.$this->cidr;
    }
}
