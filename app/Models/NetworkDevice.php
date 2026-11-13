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

    /**
     * Place in the network, from the Internet down. The level orders the
     * layers of the topology; other devices take the level of their distance
     * to the edge.
     *
     * @var array<string, array{label: string, short: string, level: ?int}>
     */
    public const ROLES = [
        'edge' => ['label' => 'Routeur de bordure / pare-feu', 'short' => 'Bordure', 'level' => 1],
        'core' => ['label' => 'Cœur', 'short' => 'Cœur', 'level' => 2],
        'distribution' => ['label' => 'Distribution', 'short' => 'Distribution', 'level' => 3],
        'access' => ['label' => 'Accès', 'short' => 'Accès', 'level' => 4],
        'other' => ['label' => 'Autre', 'short' => 'Autre', 'level' => null],
    ];

    /** @var array<string, string> */
    public const VERSIONS = ['2c' => 'SNMP v2c', '3' => 'SNMP v3'];

    /** @var array<string, string> */
    public const SECURITY_LEVELS = ['authPriv' => 'Authentification et chiffrement', 'authNoPriv' => 'Authentification sans chiffrement', 'noAuthNoPriv' => 'Sans authentification'];

    /** @var list<string> */
    public const AUTH_PROTOCOLS = ['SHA', 'SHA-256', 'SHA-512', 'MD5'];

    /** @var list<string> */
    public const PRIVACY_PROTOCOLS = ['AES', 'AES-256', 'DES'];

    /** Seconds to wait for each SNMP answer, and tries after the first, unless set on the device. */
    public const SNMP_TIMEOUT = 2;

    public const SNMP_RETRIES = 1;

    /**
     * Values per SNMP packet when walking a table. Fewer values make smaller
     * packets, which a VPN tunnel does not have to fragment; 0 walks one value
     * at a time, like snmpwalk.
     *
     * @var array<int, string>
     */
    public const SNMP_BULK = [10 => 'Standard, 10 valeurs par paquet', 5 => 'Réduit, 5 valeurs', 2 => 'Petits paquets, 2 valeurs', 0 => 'Une valeur à la fois'];

    /** Failed polls in a row before a device counts as unreachable. */
    public const FAILURES_BEFORE_ALERT = 2;

    protected $fillable = ['name', 'role', 'host', 'port', 'snmp_version', 'snmp_timeout', 'snmp_retries', 'snmp_bulk', 'credentials', 'location_id', 'description', 'poll_interval', 'api_driver', 'api_port', 'api_verify_tls'];

    protected $hidden = ['credentials', 'api_credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', 'api_credentials' => 'encrypted:array', 'api_verify_tls' => 'boolean', 'api_port' => 'integer', 'next_poll_at' => 'datetime', 'poll_requested_at' => 'datetime',
            'polled_at' => 'datetime', 'port' => 'integer', 'poll_interval' => 'integer',
            'snmp_timeout' => 'integer', 'snmp_retries' => 'integer', 'snmp_bulk' => 'integer', 'poll_failures' => 'integer',
        ];
    }

    /** @return list<string> */
    public function auditIgnored(): array
    {
        return [
            'next_poll_at', 'poll_token', 'poll_status', 'poll_error', 'poll_failures', 'api_error', 'poll_requested_at', 'polled_at',
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

    public function destinations(): HasMany
    {
        return $this->hasMany(PortDestination::class);
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(WallOutlet::class);
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
