<?php

namespace App\Livewire;

use App\Livewire\Concerns\EditsNetworkDevice;
use App\Models\DevicePort;
use App\Models\IpAddress;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Vlan;
use App\Models\WallOutlet;
use App\Support\Audit;
use App\Support\DevicePollQueue;
use App\Support\IpNetwork;
use App\Support\MacAddress;
use App\Support\PortMedia;
use App\Support\VlanColor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class DeviceDetail extends Component
{
    use EditsNetworkDevice;

    #[Locked]
    public int $deviceId;

    #[Url(as: 'port', except: '')]
    public string $selected = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $logical = false;

    /** Interface index of the port whose WAN settings are being edited. */
    #[Locked]
    public ?int $wanPort = null;

    public array $wan = [];

    /** Wall outlet patched to the selected port, as chosen in its panel. */
    public string $outlet = '';

    public function mount(NetworkDevice $device): void
    {
        $this->deviceId = $device->id;
        $this->syncOutlet();
    }

    public function close(): void
    {
        $this->resetDeviceForm();
    }

    public function select(int $ifIndex): void
    {
        $this->selected = $this->selected === (string) $ifIndex ? '' : (string) $ifIndex;
        $this->syncOutlet();
    }

    private function syncOutlet(): void
    {
        $port = $this->selectedPort();
        $this->outlet = (string) ($port ? WallOutlet::where('network_device_id', $this->deviceId)->where('port', $port->name)->value('id') : '');
    }

    /**
     * Patches the chosen wall outlet to the selected port, or unpatches the
     * port with an empty choice. An outlet moved from another port leaves it.
     */
    public function updatedOutlet(): void
    {
        Gate::authorize('edit-inventory');
        $port = $this->selectedPort();
        abort_unless($port?->physical, 409);

        WallOutlet::patch($this->deviceId, $port->name, ctype_digit($this->outlet) ? (int) $this->outlet : null);

        $this->dispatch('nexora-toast', tone: 'success', message: $this->outlet !== ''
            ? __('Prise brassée sur :port.', ['port' => $port->name])
            : __('Prise débrassée de :port.', ['port' => $port->name]));
    }

    public function openWan(int $ifIndex): void
    {
        Gate::authorize('edit-inventory');
        $port = DevicePort::where('network_device_id', $this->deviceId)->where('if_index', $ifIndex)->firstOrFail();
        $this->resetValidation();
        $this->wanPort = $port->if_index;
        $this->wan = ['enabled' => $port->wan, 'label' => $port->wan_label ?? '', 'address' => $port->wan_address ?? '', 'reference' => $port->wan_reference ?? ''];
    }

    public function closeWan(): void
    {
        $this->reset('wanPort', 'wan');
        $this->resetValidation();
    }

    /** Marks a port as a way out to the Internet, shown in the topology. */
    public function saveWan(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->wanPort !== null, 409);
        $port = DevicePort::where('network_device_id', $this->deviceId)->where('if_index', $this->wanPort)->firstOrFail();
        foreach (['label', 'address', 'reference'] as $key) {
            $this->wan[$key] = trim((string) ($this->wan[$key] ?? ''));
        }
        $data = $this->validate([
            'wan.enabled' => ['boolean'],
            'wan.label' => ['nullable', 'string', 'max:100'],
            'wan.address' => ['nullable', 'ip'],
            'wan.reference' => ['nullable', 'string', 'max:100'],
        ], ['wan.address.ip' => __('Saisissez une adresse IPv4 ou IPv6 valide.')])['wan'];

        $enabled = (bool) ($data['enabled'] ?? false);
        $values = [
            'wan' => $enabled,
            'wan_label' => $enabled ? ($data['label'] ?: null) : null,
            'wan_address' => $enabled && $data['address'] ? IpNetwork::address($data['address']) : null,
            'wan_reference' => $enabled ? ($data['reference'] ?: null) : null,
        ];
        $changes = [];
        foreach ($values as $key => $value) {
            if ($port->{$key} !== $value) {
                $changes[$key] = [$port->{$key}, $value];
            }
        }
        if ($changes !== []) {
            $port->forceFill($values)->save();
            Audit::record('device.port_wan', NetworkDevice::findOrFail($this->deviceId), ['port' => [null, $port->name]] + $changes);
        }

        $this->closeWan();
        $this->dispatch('nexora-toast', tone: 'success', message: $enabled ? __('Sortie WAN enregistrée.') : __('Le port n’est plus une sortie WAN.'));
    }

    private function selectedPort(): ?DevicePort
    {
        return ctype_digit($this->selected) ? DevicePort::where('network_device_id', $this->deviceId)->where('if_index', (int) $this->selected)->first() : null;
    }

    public function poll(DevicePollQueue $queue): void
    {
        Gate::authorize('administer');
        $message = $queue->enqueue($this->deviceId);
        $this->dispatch('nexora-toast', message: $message ?? __('Interrogation mise en file d’attente.'), tone: $message ? 'info' : 'success');
    }

    /**
     * Forces the connector of a port when detection gets it wrong, or goes
     * back to detection with an empty value. The choice survives polls.
     */
    public function setMedia(int $ifIndex, string $media): void
    {
        Gate::authorize('administer');
        abort_unless($media === '' || isset(PortMedia::LABELS[$media]), 422);
        $device = NetworkDevice::findOrFail($this->deviceId);
        $port = $device->ports()->where('if_index', $ifIndex)->where('physical', true)->firstOrFail();
        $override = $media === '' ? null : $media;
        if ($port->media_override === $override) {
            return;
        }

        $previous = $port->media_override;
        $port->forceFill(['media_override' => $override])->save();
        Audit::record('device.port_media', $device, [
            'port' => [null, $port->name],
            'media' => [$previous ?? 'auto', $override ?? 'auto'],
        ]);
        $this->dispatch('nexora-toast', tone: 'success', message: __('Connecteur du port :port enregistré.', ['port' => $port->name]));
    }

    /**
     * Groups physical ports by their name prefix, such as GigabitEthernet1/0/
     * and TenGigabitEthernet1/1/, so uplink modules are drawn apart.
     *
     * @param  Collection<int, DevicePort>  $ports
     * @return Collection<string, Collection<int, DevicePort>>
     */
    public static function faceplate(Collection $ports): Collection
    {
        return $ports->where('physical', true)
            ->sortBy(fn (DevicePort $port): string => $port->name ?? '', SORT_NATURAL | SORT_FLAG_CASE)
            ->groupBy(fn (DevicePort $port): string => preg_replace('/\d+\D*$/', '', (string) $port->name) ?: '#')
            ->map(fn (Collection $group): Collection => $group->values());
    }

    public function render(): View
    {
        $device = NetworkDevice::with('location.parent.parent')->findOrFail($this->deviceId);
        $ports = $device->ports()->get();
        $search = mb_substr(trim($this->search), 0, 100);
        $mac = MacAddress::normalize($search);

        $outlets = WallOutlet::where('network_device_id', $device->id)->get()->keyBy('port');
        $listed = $ports->filter(fn (DevicePort $port): bool => $this->logical || $port->physical)
            ->when($search !== '', fn (Collection $ports) => $ports->filter(function (DevicePort $port) use ($search, $mac, $outlets): bool {
                $haystack = mb_strtolower(implode(' ', [$port->name, $port->alias, $port->descr, $port->neighbor_name, $port->pvid, implode(' ', $port->tagged_vlans ?? []), $outlets[$port->name]->name ?? '']));

                return str_contains($haystack, mb_strtolower($search))
                    || ($mac !== null && $port->macs()->where('mac_address', $mac)->exists());
            }))
            ->sortBy(fn (DevicePort $port): string => $port->name ?? '', SORT_NATURAL | SORT_FLAG_CASE);

        $selected = ctype_digit($this->selected) ? $ports->firstWhere('if_index', (int) $this->selected) : null;
        $macs = $selected ? $selected->macs()->orderBy('vlan')->orderBy('mac_address')->get() : collect();
        $vlanNumbers = $ports->pluck('pvid')->merge($ports->pluck('tagged_vlans')->flatten())->merge($ports->pluck('untagged_vlans')->flatten())
            ->filter()->unique()->sort()->values();

        return view('livewire.device-detail', [
            'device' => $device,
            'outlets' => $outlets,
            'outletChoices' => $selected?->physical && Gate::allows('edit-inventory')
                ? WallOutlet::with('location')->where(fn ($query) => $query->whereNull('network_device_id')
                    ->orWhere(fn ($query) => $query->where('network_device_id', $device->id)->where('port', $selected->name)))
                    ->get()->sortBy(fn (WallOutlet $outlet): string => ($outlet->location_id === $device->location_id ? '0' : '1').mb_strtolower($outlet->location?->name ?? '').' '.$outlet->name, SORT_NATURAL)
                : collect(),
            'locations' => $this->editing ? Location::with('parent.parent')->orderBy('name')->get() : collect(),
            'groups' => self::faceplate($ports),
            'listed' => $listed,
            'port' => $selected,
            'macs' => $macs,
            'inventory' => $macs->isEmpty() ? collect() : IpAddress::with('subnet')->whereIn('mac_address', $macs->pluck('mac_address'))->get()->groupBy('mac_address'),
            'vlans' => Vlan::whereIn('number', $vlanNumbers)->get()->groupBy('number'),
            'vlanColors' => VlanColor::map($vlanNumbers),
            'summary' => [
                'physical' => $ports->where('physical', true)->count(),
                'up' => $ports->where('physical', true)->where('oper_status', 1)->count(),
                'macs' => $ports->sum('mac_count'),
                'trunks' => $ports->where('physical', true)->filter(fn (DevicePort $port): bool => $port->isTrunk())->count(),
            ],
        ])->layout('components.layouts.app', ['title' => $device->name]);
    }
}
