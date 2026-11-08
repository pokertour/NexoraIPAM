<?php

namespace App\Http\Resources\V1;

use App\Models\IpAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IpAddress */
class IpAddressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'address' => $this->address,
            'subnet_id' => $this->subnet_id,
            'status' => $this->status,
            'hostname' => $this->hostname,
            'discovered_hostname' => $this->resolved_hostname,
            'hostname_source' => $this->hostname_source,
            'mac_address' => $this->mac_address,
            'description' => $this->description,
            'responding' => $this->lastPingResponded(),
            'last_probe_at' => $this->last_probe_at,
            'last_seen_at' => $this->last_seen_at,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'custom_fields' => (object) ($this->custom_values ?? []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
