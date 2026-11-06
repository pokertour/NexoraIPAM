<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A MAC address learned on a switch port during the last poll. */
class DevicePortMac extends Model
{
    public $timestamps = false;

    protected $fillable = ['network_device_id', 'device_port_id', 'mac_address', 'vlan', 'ip_address', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    public function port(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'device_port_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'network_device_id');
    }
}
