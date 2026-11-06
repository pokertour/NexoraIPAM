<?php

namespace App\Livewire;

use App\Models\DevicePort;
use App\Models\IpAddress;
use App\Models\NetworkDevice;
use App\Models\Vlan;
use App\Support\DevicePollQueue;
use App\Support\MacAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class DeviceDetail extends Component
{
    #[Locked]
    public int $deviceId;

    #[Url(as: 'port', except: '')]
    public string $selected = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $logical = false;

    public function mount(NetworkDevice $device): void
    {
        $this->deviceId = $device->id;
    }

    public function select(int $ifIndex): void
    {
        $this->selected = $this->selected === (string) $ifIndex ? '' : (string) $ifIndex;
    }

    public function poll(DevicePollQueue $queue): void
    {
        Gate::authorize('administer');
        $message = $queue->enqueue($this->deviceId);
        $this->dispatch('nexora-toast', message: $message ?? __('Interrogation mise en file d’attente.'), tone: $message ? 'info' : 'success');
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

        $listed = $ports->filter(fn (DevicePort $port): bool => $this->logical || $port->physical)
            ->when($search !== '', fn (Collection $ports) => $ports->filter(function (DevicePort $port) use ($search, $mac): bool {
                $haystack = mb_strtolower(implode(' ', [$port->name, $port->alias, $port->descr, $port->neighbor_name, $port->pvid, implode(' ', $port->tagged_vlans ?? [])]));

                return str_contains($haystack, mb_strtolower($search))
                    || ($mac !== null && $port->macs()->where('mac_address', $mac)->exists());
            }))
            ->sortBy(fn (DevicePort $port): string => $port->name ?? '', SORT_NATURAL | SORT_FLAG_CASE);

        $selected = ctype_digit($this->selected) ? $ports->firstWhere('if_index', (int) $this->selected) : null;
        $macs = $selected ? $selected->macs()->orderBy('vlan')->orderBy('mac_address')->get() : collect();
        $vlanNumbers = $ports->pluck('pvid')->merge($ports->pluck('tagged_vlans')->flatten())->filter()->unique()->values();

        return view('livewire.device-detail', [
            'device' => $device,
            'groups' => self::faceplate($ports),
            'listed' => $listed,
            'port' => $selected,
            'macs' => $macs,
            'inventory' => $macs->isEmpty() ? collect() : IpAddress::with('subnet')->whereIn('mac_address', $macs->pluck('mac_address'))->get()->groupBy('mac_address'),
            'vlans' => Vlan::whereIn('number', $vlanNumbers)->get()->groupBy('number'),
            'summary' => [
                'physical' => $ports->where('physical', true)->count(),
                'up' => $ports->where('physical', true)->where('oper_status', 1)->count(),
                'macs' => $ports->sum('mac_count'),
                'trunks' => $ports->where('physical', true)->filter(fn (DevicePort $port): bool => $port->isTrunk())->count(),
            ],
        ])->layout('components.layouts.app', ['title' => $device->name]);
    }
}
