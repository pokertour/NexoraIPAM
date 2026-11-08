<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\DeviceResource;
use App\Http\Resources\V1\LocationResource;
use App\Livewire\GlobalSearch;
use App\Models\Location;
use App\Models\NetworkDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Read-only endpoints: account, locations, devices and search. */
class ReadController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role,
            'token' => ['name' => $user->currentAccessToken()?->name, 'abilities' => array_values(array_filter(['read', 'write'], fn (string $ability): bool => $user->tokenCan($ability)))],
        ]]);
    }

    public function locations(): AnonymousResourceCollection
    {
        return LocationResource::collection(Location::with('parent.parent')->orderBy('kind')->orderBy('name')->get());
    }

    public function location(Location $location): LocationResource
    {
        return new LocationResource($location->load('parent.parent'));
    }

    public function devices(): AnonymousResourceCollection
    {
        return DeviceResource::collection(NetworkDevice::orderBy('name')->get());
    }

    public function device(NetworkDevice $device): DeviceResource
    {
        return new DeviceResource($device->load(['ports' => fn ($query) => $query->orderBy('if_index'), 'ports.macs']));
    }

    public function search(Request $request): JsonResponse
    {
        $search = new GlobalSearch;
        $search->query = (string) $request->query('q', '');

        return response()->json(['data' => $search->results()]);
    }
}
