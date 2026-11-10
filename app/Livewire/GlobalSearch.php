<?php

namespace App\Livewire;

use App\Models\DevicePort;
use App\Models\DevicePortMac;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Subnet;
use App\Models\Tag;
use App\Models\Vlan;
use App\Models\WallOutlet;
use App\Support\IpNetwork;
use App\Support\MacAddress;
use App\Support\Navigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\View\View;
use Livewire\Component;

class GlobalSearch extends Component
{
    private const LIMIT = 6;

    public string $query = '';

    /**
     * @return array<string, list<array{label: string, detail: ?string, url: string, icon: string}>>
     */
    public function results(): array
    {
        $term = mb_substr(trim($this->query), 0, 100);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.$term.'%';
        $mac = MacAddress::normalize($term);
        $ip = IpNetwork::address($term);
        $groups = ['Pages' => array_slice(Navigation::search($term), 0, self::LIMIT)];

        $subnets = Subnet::with('location.parent.parent')
            ->where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('cidr', $like)->orWhereLike('description', $like)
                ->orWhereLike('domain', $like)->orWhereHas('tags', fn (Builder $query) => $query->whereLike('name', $like)))
            ->orderBy('name')->limit(self::LIMIT)->get();
        if ($ip !== null) {
            $containing = Subnet::with('location.parent.parent')->get()
                ->filter(fn (Subnet $subnet): bool => IpNetwork::contains($subnet->cidr, $ip))
                ->sortByDesc(fn (Subnet $subnet): int => (int) explode('/', $subnet->cidr)[1]);
            $subnets = $containing->merge($subnets)->unique('id')->take(self::LIMIT);
        }
        $groups['Sous-réseaux'] = $subnets->map(fn (Subnet $subnet): array => [
            'label' => $subnet->name, 'detail' => $subnet->cidr.($subnet->location ? ' · '.$subnet->location->path() : ''),
            'url' => route('addresses', ['subnet' => $subnet->id]), 'icon' => 'subnets',
        ])->values()->all();

        $groups['Adresses IP'] = IpAddress::with('subnet')
            ->where(fn (Builder $query) => $query->whereLike('address', $ip ?? $like)->orWhereLike('hostname', $like)
                ->orWhereLike('resolved_hostname', $like)->orWhereLike('description', $like)
                ->when($mac, fn (Builder $query) => $query->orWhere('mac_address', $mac))
                ->orWhereHas('tags', fn (Builder $query) => $query->whereLike('name', $like)))
            ->orderBy('address_key')->limit(self::LIMIT)->get()
            ->map(fn (IpAddress $address): array => [
                'label' => $address->address,
                'detail' => implode(' · ', array_filter([$address->hostname ?: $address->resolved_hostname, $address->mac_address, $address->subnet->name])),
                'url' => route('addresses', ['subnet' => $address->subnet_id, 'search' => $address->address]), 'icon' => 'addresses',
            ])->all();

        $groups['Ports de switch'] = $mac
            ? DevicePortMac::with(['port', 'device'])->where('mac_address', $mac)->limit(self::LIMIT)->get()
                ->map(fn (DevicePortMac $entry): array => [
                    'label' => $entry->device->name.' · '.$entry->port->name,
                    'detail' => trim($mac.($entry->vlan ? ' · VLAN '.$entry->vlan : '').($entry->ip_address ? ' · '.$entry->ip_address : '')),
                    'url' => route('devices.show', ['device' => $entry->network_device_id, 'port' => $entry->port->if_index]), 'icon' => 'switch',
                ])->all()
            : DevicePort::with('device')->where(fn (Builder $query) => $query->whereLike('alias', $like)->orWhereLike('neighbor_name', $like))
                ->limit(self::LIMIT)->get()
                ->map(fn (DevicePort $port): array => [
                    'label' => $port->device->name.' · '.$port->name,
                    'detail' => implode(' · ', array_filter([$port->alias, $port->neighbor_name])),
                    'url' => route('devices.show', ['device' => $port->network_device_id, 'port' => $port->if_index]), 'icon' => 'switch',
                ])->all();

        $groups['VLAN'] = Vlan::with('location.parent.parent')
            ->where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('description', $like)
                ->when(ctype_digit($term) && (int) $term <= 4094, fn (Builder $query) => $query->orWhere('number', (int) $term)))
            ->orderBy('number')->limit(self::LIMIT)->get()
            ->map(fn (Vlan $vlan): array => [
                'label' => $vlan->number.' · '.$vlan->name, 'detail' => $vlan->location?->path(),
                'url' => route('vlans', ['search' => $vlan->name]), 'icon' => 'vlans',
            ])->all();

        $groups['Équipements'] = NetworkDevice::where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('host', $like)
            ->orWhereLike('sys_name', $like)->orWhereLike('description', $like))
            ->orderBy('name')->limit(self::LIMIT)->get()
            ->map(fn (NetworkDevice $device): array => [
                'label' => $device->name, 'detail' => $device->host.($device->sys_name ? ' · '.$device->sys_name : ''),
                'url' => route('devices.show', $device), 'icon' => 'switch',
            ])->all();

        $groups['Prises murales'] = WallOutlet::with(['location.parent.parent', 'device.ports' => fn ($query) => $query->where('physical', true)])
            ->where(fn (Builder $query) => $query->whereLike('name', $like)->orWhereLike('description', $like))
            ->orderBy('name')->limit(self::LIMIT)->get()
            ->map(function (WallOutlet $outlet): array {
                $port = $outlet->devicePort();

                return [
                    'label' => $outlet->name,
                    'detail' => implode(' · ', array_filter([$outlet->location?->path(), $outlet->device ? '→ '.$outlet->device->name.' '.$outlet->port : __('Libre')])),
                    'url' => $outlet->device
                        ? route('devices.show', ['device' => $outlet->network_device_id] + ($port ? ['port' => $port->if_index] : []))
                        : route('outlets', ['search' => $outlet->name]),
                    'icon' => 'outlet',
                ];
            })->all();

        $groups['Emplacements'] = Location::with('parent.parent')->whereLike('name', $like)->orderBy('name')->limit(self::LIMIT)->get()
            ->map(fn (Location $location): array => [
                'label' => $location->name, 'detail' => $location->parent?->path(),
                'url' => route('subnets', ['location' => $location->id]), 'icon' => 'locations',
            ])->all();

        $groups['Étiquettes'] = Tag::whereLike('name', $like)->orderBy('name')->limit(self::LIMIT)->get()
            ->map(fn (Tag $tag): array => [
                'label' => '#'.$tag->name, 'detail' => $tag->description,
                'url' => route('subnets', ['tag' => $tag->id]), 'icon' => 'tag',
            ])->all();

        return array_filter($groups);
    }

    public function render(): View
    {
        return view('livewire.global-search', ['groups' => $this->results()]);
    }
}
