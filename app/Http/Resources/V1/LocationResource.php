<?php

namespace App\Http\Resources\V1;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Location */
class LocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'path' => $this->path(),
            'parent_id' => $this->parent_id,
            'description' => $this->description,
            'discovery_interval' => $this->discovery_interval,
        ];
    }
}
