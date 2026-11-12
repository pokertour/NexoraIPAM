<?php

namespace App\Livewire;

use App\Models\DevicePort;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\PortDestination;
use App\Models\Vlan;
use App\Models\WallOutlet;
use App\Support\VlanColor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Patch panel sheet: every switch of a location drawn as a grid of ports,
 * coloured by native VLAN, with the wall outlet patched to each port.
 */
class PatchPlan extends Component
{
    #[Url(except: '')]
    public string $location = '';

    /** Device of the port being patched. */
    #[Locked]
    public ?int $deviceId = null;

    /** Interface index of the port being patched. */
    #[Locked]
    public ?int $ifIndex = null;

    /** Where the port leads: a wall outlet, or free text for anything else. */
    public string $mode = 'outlet';

    /** Outlet chosen for that port, empty to free it. */
    public string $outlet = '';

    /** Free destination, such as a printer or another rack. */
    public string $destination = '';

    public function patch(int $deviceId, int $ifIndex): void
    {
        Gate::authorize('edit-inventory');
        $port = $this->port($deviceId, $ifIndex);
        $this->resetValidation();
        $this->deviceId = $deviceId;
        $this->ifIndex = $port->if_index;
        $this->outlet = (string) (WallOutlet::where('network_device_id', $deviceId)->where('port', $port->name)->value('id') ?? '');
        $this->destination = (string) PortDestination::where('network_device_id', $deviceId)->where('port', $port->name)->value('label');
        $this->mode = $this->outlet === '' && $this->destination !== '' ? 'text' : 'outlet';
    }

    public function close(): void
    {
        $this->reset('deviceId', 'ifIndex', 'mode', 'outlet', 'destination');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('edit-inventory');
        abort_if($this->deviceId === null || $this->ifIndex === null, 409);
        $port = $this->port($this->deviceId, $this->ifIndex);
        $this->destination = trim($this->destination);
        $this->validate([
            'mode' => ['required', Rule::in(['outlet', 'text'])],
            'outlet' => ['nullable', 'integer', 'exists:wall_outlets,id'],
            'destination' => ['nullable', 'string', 'max:'.PortDestination::MAX_LENGTH],
        ]);
        $text = $this->mode === 'text';
        $outletId = ! $text && ctype_digit($this->outlet) ? (int) $this->outlet : null;

        PortDestination::assign($this->deviceId, $port->name, $outletId, $text ? $this->destination : null);

        $this->dispatch('nexora-toast', tone: 'success', message: match (true) {
            $outletId !== null => __('Prise brassée sur :port.', ['port' => $port->name]),
            $text && $this->destination !== '' => __('Destination de :port enregistrée.', ['port' => $port->name]),
            default => __('Port :port libéré.', ['port' => $port->name]),
        });
        $this->close();
    }

    private function port(int $deviceId, int $ifIndex): DevicePort
    {
        return DevicePort::where('network_device_id', $deviceId)->where('if_index', $ifIndex)->where('physical', true)->firstOrFail();
    }

    public function render(): View
    {
        $devices = NetworkDevice::with(['location.parent.parent', 'rackItem.rack', 'ports' => fn ($query) => $query->where('physical', true)])
            ->when(ctype_digit($this->location), fn ($query) => $query->whereIn('location_id', Location::findOrFail($this->location)->descendantsAndSelf()))
            ->get()
            ->filter(fn (NetworkDevice $device): bool => $device->ports->isNotEmpty())
            ->sortBy(fn (NetworkDevice $device): string => mb_strtolower(($device->location?->path() ?? "\u{10FFFF}").' '.$device->name), SORT_NATURAL)
            ->values();

        $outlets = WallOutlet::whereIn('network_device_id', $devices->pluck('id'))->get()
            ->groupBy('network_device_id')->map(fn (Collection $outlets): Collection => $outlets->keyBy('port'));
        $destinations = PortDestination::whereIn('network_device_id', $devices->pluck('id'))->get()
            ->groupBy('network_device_id')->map(fn (Collection $destinations): Collection => $destinations->keyBy('port'));
        $ports = $devices->flatMap->ports;
        $vlanNumbers = $ports->pluck('pvid')->filter()->unique()->sort()->values();
        $editing = $this->deviceId !== null ? $devices->firstWhere('id', $this->deviceId) : null;
        $editedPort = $editing?->ports->firstWhere('if_index', $this->ifIndex);

        return view('livewire.patch-plan', [
            'devices' => $devices,
            'groups' => $devices->mapWithKeys(fn (NetworkDevice $device): array => [$device->id => DeviceDetail::faceplate($device->ports)]),
            'outlets' => $outlets,
            'destinations' => $destinations,
            'vlanColors' => VlanColor::map($vlanNumbers),
            'vlans' => Vlan::whereIn('number', $vlanNumbers)->get()->groupBy('number'),
            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
            'patched' => $outlets->sum(fn (Collection $outlets): int => $outlets->count()) + $destinations->sum(fn (Collection $destinations): int => $destinations->count()),
            'editing' => $editing,
            'editedPort' => $editedPort,
            'outletChoices' => $editedPort
                ? WallOutlet::with('location')->where(fn ($query) => $query->whereNull('network_device_id')
                    ->orWhere(fn ($query) => $query->where('network_device_id', $editing->id)->where('port', $editedPort->name)))
                    ->get()->sortBy(fn (WallOutlet $outlet): string => ($outlet->location_id === $editing->location_id ? '0' : '1').mb_strtolower($outlet->location?->name ?? '').' '.$outlet->name, SORT_NATURAL)
                : collect(),
        ])->layout('components.layouts.app', ['title' => __('Plan de brassage')]);
    }
}
