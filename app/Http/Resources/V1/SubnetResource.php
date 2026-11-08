<?php

namespace App\Http\Resources\V1;

use App\Models\Subnet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subnet */
class SubnetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'cidr' => $this->cidr,
            'description' => $this->description,
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'path' => $this->location->path()] : null),
            'vlan' => $this->whenLoaded('vlan', fn () => $this->vlan ? ['id' => $this->vlan->id, 'number' => $this->vlan->number, 'name' => $this->vlan->name] : null),
            'parent_subnet_id' => $this->parent_subnet_id,
            'gateway' => $this->gateway,
            'dns_servers' => $this->dnsServers(),
            'domain' => $this->domain,
            'dhcp_range' => $this->dhcp_start ? ['start' => $this->dhcp_start, 'end' => $this->dhcp_end] : null,
            'assigned' => $this->whenCounted('assigned'),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'custom_fields' => (object) ($this->custom_values ?? []),
            'scanned_at' => $this->scanned_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
