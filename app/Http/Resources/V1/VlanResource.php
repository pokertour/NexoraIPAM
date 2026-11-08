<?php

namespace App\Http\Resources\V1;

use App\Models\Vlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vlan */
class VlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->displayColor(),
            'location' => $this->whenLoaded('location', fn () => $this->location ? ['id' => $this->location->id, 'path' => $this->location->path()] : null),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->pluck('name')),
            'custom_fields' => (object) ($this->custom_values ?? []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
