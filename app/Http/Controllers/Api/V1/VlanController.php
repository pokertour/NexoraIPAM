<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VlanResource;
use App\Models\Location;
use App\Models\Vlan;
use App\Support\ApiMetadata;
use App\Support\VlanColor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VlanController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return VlanResource::collection(Vlan::with(['location.parent.parent', 'tags'])
            ->when($request->filled('number'), fn (Builder $query) => $query->where('number', $request->integer('number')))
            ->when($request->integer('location'), fn (Builder $query) => $query->whereIn('location_id', Location::findOrFail($request->integer('location'))->descendantsAndSelf()))
            ->orderBy('number')->paginate(min(200, max(1, $request->integer('per_page', 100)))));
    }

    public function show(Vlan $vlan): VlanResource
    {
        return new VlanResource($vlan->load(['location.parent.parent', 'tags']));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('edit-inventory');

        return (new VlanResource($this->save($request, new Vlan)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Vlan $vlan): VlanResource
    {
        Gate::authorize('edit-inventory');

        return new VlanResource($this->save($request, $vlan));
    }

    public function destroy(Vlan $vlan): JsonResponse
    {
        Gate::authorize('edit-inventory');
        $vlan->delete();

        return response()->json(null, 204);
    }

    private function save(Request $request, Vlan $vlan): Vlan
    {
        $creating = ! $vlan->exists;
        $location = $request->has('location_id') ? $request->input('location_id') : $vlan->location_id;
        $data = $request->validate([
            'number' => [$creating ? 'required' : 'sometimes', 'integer', 'between:1,4094', Rule::unique('vlans', 'number')->where('scope_id', (int) ($location ?? 0))->ignore($vlan)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', Rule::in(VlanColor::PALETTE)],
        ]);
        $metadata = ApiMetadata::validate($request, 'vlans', $creating ? null : $vlan);

        if (! $creating && array_key_exists('location_id', $data) && $data['location_id'] !== null && $data['location_id'] !== $vlan->location_id
            && $vlan->subnets()->where(fn (Builder $query) => $query->whereNull('location_id')->orWhereNotIn('location_id', Location::findOrFail($data['location_id'])->descendantsAndSelf()))->exists()) {
            throw ValidationException::withMessages(['location_id' => __('Des sous-réseaux liés se trouvent en dehors de cet emplacement.')]);
        }

        $vlan->fill($data);
        ApiMetadata::fill($vlan, $metadata);
        $vlan->save();
        ApiMetadata::syncTags($vlan, $metadata);

        return $vlan->load(['location.parent.parent', 'tags']);
    }
}
