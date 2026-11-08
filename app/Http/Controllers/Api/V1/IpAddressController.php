<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\IpAddressResource;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Support\AlertMonitor;
use App\Support\ApiMetadata;
use App\Support\IpNetwork;
use App\Support\MacAddress;
use App\Support\SubnetSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IpAddressController extends Controller
{
    public function __construct(private SubnetSpace $space, private AlertMonitor $monitor) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));
        $mac = MacAddress::normalize($search);

        return IpAddressResource::collection(IpAddress::with('tags')
            ->when($request->filled('subnet'), fn (Builder $query) => $query->where('subnet_id', $request->integer('subnet')))
            ->when(isset(IpAddress::STATUSES[$request->query('status')]), fn (Builder $query) => $query->where('status', $request->query('status')))
            ->when($request->filled('address'), fn (Builder $query) => $query->where('address', IpNetwork::address((string) $request->query('address')) ?? $request->query('address')))
            ->when($request->filled('mac'), fn (Builder $query) => $query->where('mac_address', MacAddress::normalize((string) $request->query('mac'))))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereLike('address', '%'.$search.'%')
                ->orWhereLike('hostname', '%'.$search.'%')->orWhereLike('resolved_hostname', '%'.$search.'%')
                ->when($mac, fn (Builder $query) => $query->orWhere('mac_address', $mac))))
            ->orderBy('subnet_id')->orderBy('address_key')
            ->paginate(min(500, max(1, $request->integer('per_page', 100)))));
    }

    public function show(IpAddress $address): IpAddressResource
    {
        return new IpAddressResource($address->load('tags'));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('edit-inventory');

        return (new IpAddressResource($this->save($request, new IpAddress)))->response()->setStatusCode(201);
    }

    public function update(Request $request, IpAddress $address): IpAddressResource
    {
        Gate::authorize('edit-inventory');

        return new IpAddressResource($this->save($request, $address));
    }

    public function destroy(IpAddress $address): JsonResponse
    {
        Gate::authorize('edit-inventory');
        $subnet = $address->subnet;
        $address->delete();
        $this->monitor->checkCapacity($subnet);

        return response()->json(null, 204);
    }

    private function save(Request $request, IpAddress $record): IpAddress
    {
        $creating = ! $record->exists;
        if (is_string($request->input('address'))) {
            $request->merge(['address' => IpNetwork::address(trim($request->input('address'))) ?? $request->input('address')]);
        }
        $data = $request->validate([
            'subnet_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:subnets,id'],
            'address' => [$creating ? 'required' : 'sometimes', 'ip'],
            'hostname' => ['sometimes', 'nullable', 'string', 'max:253'],
            'status' => ['sometimes', Rule::in(array_keys(IpAddress::STATUSES))],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $metadata = ApiMetadata::validate($request, 'addresses', $creating ? null : $record);

        $previousSubnet = $record->subnet_id;
        $record = DB::transaction(function () use ($record, $data, $metadata, $creating): IpAddress {
            if (! $creating) {
                $record = IpAddress::lockForUpdate()->findOrFail($record->id);
            }
            $subnet = Subnet::lockForUpdate()->findOrFail($data['subnet_id'] ?? $record->subnet_id);
            $address = $data['address'] ?? $record->address;
            if (! IpNetwork::assignable($subnet->cidr, $address) || $this->space->delegated($subnet, $address)) {
                throw ValidationException::withMessages(['address' => __('Cette adresse n’est pas attribuable dans ce sous-réseau.')]);
            }
            if (IpAddress::where('subnet_id', $subnet->id)->where('address', $address)->whereKeyNot($record->id)->exists()) {
                throw ValidationException::withMessages(['address' => __('validation.unique', ['attribute' => 'address'])]);
            }

            $record->fill(['status' => $creating ? 'used' : $record->status] + $data);
            if (! $creating && $record->isDirty(['address', 'subnet_id'])) {
                $record->forceFill(['last_probe_at' => null, 'last_seen_at' => null, 'resolved_hostname' => null, 'hostname_source' => null, 'mac_address' => null, 'last_probe_responded' => null]);
            }
            ApiMetadata::fill($record, $metadata);
            $record->save();
            ApiMetadata::syncTags($record, $metadata);

            return $record;
        }, attempts: 3);

        foreach (Subnet::whereKey(array_unique(array_filter([$previousSubnet, $record->subnet_id])))->get() as $subnet) {
            $this->monitor->checkCapacity($subnet);
        }

        return $record->load('tags');
    }
}
