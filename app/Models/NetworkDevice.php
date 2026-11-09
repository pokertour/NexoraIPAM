<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class NetworkDevice extends Model
{
    use Auditable, HasFactory;

    /** @var array<string, string> */
    public const VERSIONS = ['2c' => 'SNMP v2c', '3' => 'SNMP v3'];

    /** @var array<string, string> */
    public const SECURITY_LEVELS = ['authPriv' => 'Authentification et chiffrement', 'authNoPriv' => 'Authentification sans chiffrement', 'noAuthNoPriv' => 'Sans authentification'];

    /** @var list<string> */
    public const AUTH_PROTOCOLS = ['SHA', 'SHA-256', 'SHA-512', 'MD5'];

    /** @var list<string> */
    public const PRIVACY_PROTOCOLS = ['AES', 'AES-256', 'DES'];

    protected $fillable = ['name', 'host', 'port', 'snmp_version', 'credentials', 'location_id', 'description', 'poll_interval', 'api_driver', 'api_port', 'api_verify_tls'];

    protected $hidden = ['credentials', 'api_credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', 'api_credentials' => 'encrypted:array', 'api_verify_tls' => 'boolean', 'api_port' => 'integer', 'next_poll_at' => 'datetime', 'poll_requested_at' => 'datetime',
            'polled_at' => 'datetime', 'port' => 'integer', 'poll_interval' => 'integer',
        ];
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return [
            'next_poll_at', 'poll_token', 'poll_status', 'poll_error', 'api_error', 'poll_requested_at', 'polled_at',
            'sys_name', 'sys_descr', 'sys_object_id', 'sys_uptime', 'arp_entries',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name.' · '.$this->host;
    }

    public function pollPending(): bool
    {
        return $this->poll_status === 'queued' || ($this->poll_status === 'running'
            && $this->updated_at?->isAfter(now()->subMinutes(10)));
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function ports(): HasMany
    {
        return $this->hasMany(DevicePort::class);
    }

    public function macs(): HasMany
    {
        return $this->hasMany(DevicePortMac::class);
    }

    public function rackItem(): HasOne
    {
        return $this->hasOne(RackItem::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->orderBy('name');
    }
}
