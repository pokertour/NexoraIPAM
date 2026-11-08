<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\IpAddressResource;
use App\Http\Resources\V1\SubnetResource;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Support\AddressAvailability;
use App\Support\AlertMonitor;
use App\Support\ApiMetadata;
use App\Support\IpNetwork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubnetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));
        $location = $request->integer('location') ?: null;

        return SubnetResource::collection(Subnet::with(['location.parent.parent', 'vlan', 'tags'])
            ->withCount(['addresses as assigned' => fn (Builder $query) => $query->whereIn('status', ['used', 'reserved'])])
            ->when($location, fn (Builder $query) => $query->whereIn('location_id', Location::findOrFail($location)->descendantsAndSelf()))
            ->when($request->filled('tag'), fn (Builder $query) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('name', $request->query('tag'))))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereLike('name', '%'.$search.'%')->orWhereLike('cidr', '%'.$search.'%')))
            ->orderBy('name')->paginate(min(200, max(1, $request->integer('per_page', 50)))));
    }

    public function show(Subnet $subnet): SubnetResource
    {
        return new SubnetResource($subnet->load(['location.parent.parent', 'vlan', 'tags'])->loadCount(['addresses as assigned' => fn (Builder $query) => $query->whereIn('status', ['used', 'reserved'])]));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('edit-inventory');

        return (new SubnetResource($this->save($request, new Subnet)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Subnet $subnet): SubnetResource
    {
        Gate::authorize('edit-inventory');

        return new SubnetResource($this->save($request, $subnet));
    }

    public function destroy(Subnet $subnet): JsonResponse
    {
        Gate::authorize('edit-inventory');
        DB::transaction(function () use ($subnet): void {
            $subnet = Subnet::lockForUpdate()->findOrFail($subnet->id);
            if ($subnet->scanPending() || $subnet->addresses()->exists() || $subnet->childSubnets()->exists()) {
                abort(409, __('Ce réseau a des adresses, des sous-réseaux ou une découverte en cours. Traitez-les avant de le supprimer.'));
            }
            $subnet->delete();
        }, attempts: 3);

        return response()->json(null, 204);
    }

    public function addresses(Request $request, Subnet $subnet): AnonymousResourceCollection
    {
        return IpAddressResource::collection($subnet->addresses()->with('tags')->orderBy('address_key')
            ->paginate(min(500, max(1, $request->integer('per_page', 100)))));
    }

    /** Reserves the next free address of the subnet and returns it. */
    public function nextFree(Request $request, Subnet $subnet, AddressAvailability $availability, AlertMonitor $monitor): JsonResponse
    {
        Gate::authorize('edit-inventory');
        $data = $request->validate([
            'hostname' => ['nullable', 'string', 'max:253'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(array_keys(IpAddress::STATUSES))],
        ]);
        $metadata = ApiMetadata::validate($request, 'addresses');

        $address = DB::transaction(function () use ($subnet, $availability, $data, $metadata): IpAddress {
            $subnet = Subnet::lockForUpdate()->findOrFail($subnet->id);
            $free = $availability->nextFree($subnet);
            if ($free === null) {
                abort(409, __('Aucune adresse libre hors passerelle et plage DHCP dans ce réseau.'));
            }
            $address = $subnet->addresses()->make(['address' => $free, 'status' => $data['status'] ?? 'used'] + array_intersect_key($data, array_flip(['hostname', 'description'])));
            ApiMetadata::fill($address, $metadata);
            $address->save();
            ApiMetadata::syncTags($address, $metadata);

            return $address;
        }, attempts: 3);
        $monitor->checkCapacity($subnet->fresh());

        return (new IpAddressResource($address->load('tags')))->response()->setStatusCode(201);
    }

    private function save(Request $request, Subnet $subnet): Subnet
    {
        $creating = ! $subnet->exists;
        $input = $request->all();
        foreach (['gateway', 'dhcp_start', 'dhcp_end'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = $input[$field] === '' ? null : (IpNetwork::address(trim($input[$field])) ?? $input[$field]);
            }
        }
        if (isset($input['dns_servers'])) {
            $servers = is_array($input['dns_servers']) ? $input['dns_servers'] : preg_split('/[\s,;]+/', (string) $input['dns_servers'], -1, PREG_SPLIT_NO_EMPTY);
            $input['dns_servers'] = $servers;
        }
        if (isset($input['cidr']) && is_string($input['cidr'])) {
            $input['cidr'] = IpNetwork::normalize(trim($input['cidr'])) ?? $input['cidr'];
        }
        $request->merge($input);

        $location = array_key_exists('location_id', $input) ? $input['location_id'] : $subnet->location_id;
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'cidr' => [$creating ? 'required' : 'sometimes', 'string', 'max:49',
                fn (string $attribute, mixed $value, \Closure $fail) => IpNetwork::normalize((string) $value) === $value ? null : $fail(__('Saisissez un réseau valide au format CIDR (IPv4 ou IPv6).')),
                Rule::unique('subnets', 'cidr')->where('scope_id', (int) ($location ?? 0))->ignore($subnet)],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,id'],
            'vlan_id' => ['sometimes', 'nullable', 'integer', 'exists:vlans,id'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'gateway' => ['sometimes', 'nullable', 'ip'],
            'dns_servers' => ['sometimes', 'nullable', 'array', 'max:8'],
            'dns_servers.*' => ['ip'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i'],
            'dhcp_start' => ['sometimes', 'nullable', 'ip', 'required_with:dhcp_end'],
            'dhcp_end' => ['sometimes', 'nullable', 'ip', 'required_with:dhcp_start'],
        ]);
        $metadata = ApiMetadata::validate($request, 'subnets', $creating ? null : $subnet);

        return DB::transaction(function () use ($subnet, $data, $metadata, $creating): Subnet {
            if (! $creating) {
                $subnet = Subnet::lockForUpdate()->findOrFail($subnet->id);
            }
            $cidr = $data['cidr'] ?? $subnet->cidr;
            $locationId = array_key_exists('location_id', $data) ? $data['location_id'] : $subnet->location_id;
            $errors = [];
            foreach (['gateway', 'dhcp_start', 'dhcp_end'] as $field) {
                $value = array_key_exists($field, $data) ? $data[$field] : $subnet->{$field};
                if ($value !== null && ! IpNetwork::assignable($cidr, $value)) {
                    $errors[$field] = __('Cette adresse doit appartenir à la plage du sous-réseau.');
                }
            }
            $vlanId = array_key_exists('vlan_id', $data) ? $data['vlan_id'] : $subnet->vlan_id;
            $vlan = $vlanId ? Vlan::find($vlanId) : null;
            if ($vlan?->location_id && ! ($locationId && Location::findOrFail($locationId)->belongsToLocation($vlan->location_id))) {
                $errors['vlan_id'] = __('Sélectionnez un VLAN disponible à cet emplacement.');
            }
            if (! $creating && ($cidr !== $subnet->cidr || $locationId !== $subnet->location_id)) {
                if ($subnet->addresses()->exists() && $cidr !== $subnet->cidr) {
                    $errors['cidr'] = __('Déplacez les adresses existantes avant de modifier la plage CIDR.');
                } elseif ($subnet->parent_subnet_id || $subnet->childSubnets()->exists()) {
                    $errors['cidr'] = __('Ce réseau fait partie d’un découpage. Sa plage et son emplacement sont fixes.');
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            if (array_key_exists('dns_servers', $data)) {
                $data['dns_servers'] = $data['dns_servers'] ? implode(', ', array_map(fn (string $server): string => IpNetwork::address($server), $data['dns_servers'])) : null;
            }
            if (isset($data['domain'])) {
                $data['domain'] = strtolower(rtrim($data['domain'], '.'));
            }
            $subnet->fill($data);
            ApiMetadata::fill($subnet, $metadata);
            $subnet->save();
            ApiMetadata::syncTags($subnet, $metadata);

            return $subnet->load(['location.parent.parent', 'vlan', 'tags']);
        }, attempts: 3);
    }
}
