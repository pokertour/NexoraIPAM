<?php

namespace App\Http\Resources\V1;

use App\Models\DevicePort;
use App\Models\NetworkDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NetworkDevice */
class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'snmp_version' => $this->snmp_version,
            'location_id' => $this->location_id,
            'sys_name' => $this->sys_name,
            'sys_descr' => $this->sys_descr,
            'uptime_seconds' => $this->sys_uptime !== null ? intdiv($this->sys_uptime, 100) : null,
            'poll_status' => $this->poll_status,
            'poll_error' => $this->poll_error,
            'polled_at' => $this->polled_at,
            'ports' => $this->whenLoaded('ports', fn () => $this->ports->map(fn (DevicePort $port): array => [
                'if_index' => $port->if_index,
                'name' => $port->name,
                'alias' => $port->alias,
                'physical' => $port->physical,
                'media' => $port->connector(),
                'media_override' => $port->media_override !== null,
                'state' => $port->state(),
                'speed_mbps' => $port->speed_mbps,
                'native_vlan' => $port->pvid,
                'untagged_vlans' => $port->untagged_vlans ?? [],
                'tagged_vlans' => $port->tagged_vlans ?? [],
                'in_bps' => $port->in_bps,
                'out_bps' => $port->out_bps,
                'mac_addresses' => $port->relationLoaded('macs') ? $port->macs->map(fn ($mac): array => ['mac' => $mac->mac_address, 'vlan' => $mac->vlan, 'ip' => $mac->ip_address])->values() : $port->mac_count,
                'neighbor' => $port->neighbor_name ? ['name' => $port->neighbor_name, 'port' => $port->neighbor_port] : null,
            ])->values()),
        ];
    }
}
