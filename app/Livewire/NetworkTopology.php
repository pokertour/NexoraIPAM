<?php

namespace App\Livewire;

use App\Models\CableLink;
use App\Models\DevicePort;
use App\Models\NetworkDevice;
use App\Models\RackItem;
use App\Support\Topology;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NetworkTopology extends Component
{
    /** @var array{a: int, a_port: ?string, b: int, b_port: ?string}|null */
    #[Locked]
    public ?array $ends = null;

    #[Locked]
    public ?int $cableId = null;

    public array $form = [];

    /**
     * Opens the medium of a link: the documented cable when there is one,
     * otherwise a new cable that will confirm the detected link.
     */
    public function editMedium(int $aDevice, ?string $aPort, int $bDevice, ?string $bPort, ?int $cableId = null): void
    {
        Gate::authorize('edit-inventory');
        $cable = $cableId ? CableLink::findOrFail($cableId) : null;
        NetworkDevice::findOrFail($aDevice);
        NetworkDevice::findOrFail($bDevice);
        $this->resetValidation();
        $this->ends = ['a' => $aDevice, 'a_port' => $aPort, 'b' => $bDevice, 'b_port' => $bPort];
        $this->cableId = $cable?->id;
        $this->form = ['medium' => $cable?->medium ?? 'copper', 'label' => $cable?->label ?? ''];
    }

    public function close(): void
    {
        $this->reset('ends', 'cableId', 'form');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->ends !== null, 409);
        $this->form['label'] = trim((string) ($this->form['label'] ?? '')) ?: null;
        $data = $this->validate([
            'form.medium' => ['required', Rule::in(array_keys(CableLink::MEDIA))],
            'form.label' => ['nullable', 'string', 'max:100'],
        ])['form'];

        if ($this->cableId) {
            CableLink::findOrFail($this->cableId)->update($data);
        } else {
            // A racked device is documented through its rack item, like the cables of the racks page.
            $end = function (string $side): array {
                $item = RackItem::where('network_device_id', $this->ends[$side])->first();

                return [$side.'_rack_item_id' => $item?->id, $side.'_network_device_id' => $item ? null : $this->ends[$side], $side.'_port' => $this->ends[$side.'_port']];
            };
            CableLink::create($end('a') + $end('b') + $data);
        }

        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Type de liaison enregistré.'));
    }

    /** Forgets the documented cable: a detected link stays, without a medium. */
    public function forget(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->cableId !== null, 409);
        CableLink::findOrFail($this->cableId)->delete();

        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Liaison documentée supprimée.'));
    }

    public function render(Topology $topology): View
    {
        $devices = NetworkDevice::with('location')->orderBy('name')->get();
        $links = $topology->links();
        $wanPorts = DevicePort::where('wan', true)->whereIn('network_device_id', $devices->modelKeys())->orderBy('name')->get();

        return view('livewire.network-topology', self::layout($devices, $links, $wanPorts) + [
            'links' => $links,
        ])->layout('components.layouts.app', ['title' => __('Topologie')]);
    }

    /**
     * Lays the network out in layers, from the Internet down: edge routers,
     * then core, distribution and access. A device without a role sits one
     * layer below its closest neighbour towards the edge; devices with no
     * known link are set apart at the bottom. Within a layer, devices sit
     * under the neighbours they hang from, to limit crossings.
     *
     * @param  Collection<int, NetworkDevice>  $devices
     * @param  Collection<int, array<string, mixed>>  $links
     * @param  Collection<int, DevicePort>  $wanPorts
     * @return array{nodes: Collection<int, array{device: NetworkDevice, x: int, y: int, level: int}>, internet: ?array{x: int, y: int}, wans: list<array{port: DevicePort, x: int, y: int}>, isolatedY: ?int, width: int, height: int}
     */
    public static function layout(Collection $devices, Collection $links, Collection $wanPorts): array
    {
        $neighbours = $devices->mapWithKeys(fn (NetworkDevice $device): array => [$device->id => []])->all();
        foreach ($links as $link) {
            $neighbours[$link['a']->id][] = $link['b']->id;
            $neighbours[$link['b']->id][] = $link['a']->id;
        }
        $wanDevices = $wanPorts->pluck('network_device_id')->unique()->all();
        $edges = $devices->filter(fn (NetworkDevice $device): bool => $device->role === 'edge' || in_array($device->id, $wanDevices, true))->modelKeys();

        // Hops from the edge; without an edge, from the best connected device of each group.
        $distance = [];
        $walk = function (array $start) use (&$distance, $neighbours): void {
            $queue = $start;
            foreach ($start as $id) {
                $distance[$id] ??= 0;
            }
            while ($queue !== []) {
                $id = array_shift($queue);
                foreach ($neighbours[$id] ?? [] as $next) {
                    if (! isset($distance[$next])) {
                        $distance[$next] = $distance[$id] + 1;
                        $queue[] = $next;
                    }
                }
            }
        };
        $walk($edges);
        foreach (collect($neighbours)->sortByDesc(fn (array $list): int => count($list))->keys() as $id) {
            if (! isset($distance[$id]) && $neighbours[$id] !== []) {
                $walk([$id]);
            }
        }

        $levels = [];
        $isolated = [];
        foreach ($devices as $device) {
            $role = NetworkDevice::ROLES[$device->role]['level'] ?? null;
            if ($role === null && in_array($device->id, $edges, true)) {
                $role = 1;
            }
            if ($role === null && ! isset($distance[$device->id])) {
                $isolated[] = $device;

                continue;
            }
            $levels[$role ?? min(6, ($distance[$device->id] ?? 0) + 1)][] = $device;
        }
        ksort($levels);

        $column = 210;
        $rowHeight = 135;
        $widest = max(1, count($isolated), ...array_map('count', $levels ?: [[]]));
        $width = max(900, $widest * $column + 60);
        $top = $wanPorts->isNotEmpty() ? 175 : 70;
        $placed = [];
        $nodes = collect();

        $place = function (array $row, int $y, int $level) use (&$placed, $nodes, $neighbours, $width, $column): void {
            // Barycentre of the neighbours already placed above, then name.
            usort($row, function (NetworkDevice $left, NetworkDevice $right) use ($placed, $neighbours): int {
                $centre = function (NetworkDevice $device) use ($placed, $neighbours): float {
                    $xs = array_values(array_filter(array_map(fn (int $id): ?int => $placed[$id] ?? null, $neighbours[$device->id] ?? [])));

                    return $xs === [] ? PHP_INT_MAX : array_sum($xs) / count($xs);
                };

                return [$centre($left), $left->name] <=> [$centre($right), $right->name];
            });
            $start = ($width - (count($row) - 1) * $column) / 2;
            foreach ($row as $index => $device) {
                $x = (int) round($start + $index * $column);
                $placed[$device->id] = $x;
                $nodes->put($device->id, ['device' => $device, 'x' => $x, 'y' => $y, 'level' => $level]);
            }
        };

        $y = $top;
        foreach ($levels as $level => $devicesOfLevel) {
            $place($devicesOfLevel, $y, $level);
            $y += $rowHeight;
        }
        $isolatedY = null;
        if ($isolated !== []) {
            $isolatedY = $y - 30;
            $place($isolated, $isolatedY + 60, 0);
            $y = $isolatedY + 60 + $rowHeight;
        }

        $internet = $wanPorts->isNotEmpty() ? ['x' => (int) round($width / 2), 'y' => 62] : null;
        // Several WAN links of one device are spread side by side, so their lines and labels stay apart.
        $wans = [];
        foreach ($wanPorts->filter(fn (DevicePort $port): bool => $nodes->has($port->network_device_id))->groupBy('network_device_id') as $deviceId => $ports) {
            foreach ($ports->values() as $index => $port) {
                $shift = (int) round(($index - ($ports->count() - 1) / 2) * 44);
                // Labels go outwards: the left link of a pair is labelled on its left, the right one on its right.
                $side = $shift !== 0 ? ($shift < 0 ? 'left' : 'right') : ($nodes[$deviceId]['x'] >= $internet['x'] ? 'right' : 'left');
                $wans[] = ['port' => $port, 'x' => $nodes[$deviceId]['x'] + $shift, 'y' => $nodes[$deviceId]['y'], 'from' => $internet['x'] + (int) round($shift / 2), 'index' => $index, 'side' => $side];
            }
        }

        return [
            'nodes' => $nodes,
            'internet' => $internet,
            'wans' => $wans,
            'isolatedY' => $isolatedY,
            'width' => $width,
            'height' => max(360, $y - $rowHeight + 70),
        ];
    }
}
