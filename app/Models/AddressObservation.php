<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A MAC address and host name seen together on an IP address during a period.
 * Observations are telemetry written by discovery; the discovery run itself is
 * recorded in the audit trail.
 */
class AddressObservation extends Model
{
    public $timestamps = false;

    /** @var array<string, string> */
    public const SOURCES = ['icmp' => 'Découverte ICMP', 'snmp' => 'Table ARP SNMP', 'dhcp' => 'Bail DHCP'];

    protected $fillable = ['mac_address', 'hostname', 'source', 'location', 'first_seen_at', 'last_seen_at', 'times_seen'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class, 'ip_address_id');
    }
}
