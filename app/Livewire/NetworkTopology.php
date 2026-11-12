<?php

namespace App\Livewire;

use App\Models\CableLink;
use App\Models\DevicePort;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\RackItem;
use App\Support\Topology;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class NetworkTopology extends Component
{
    /** Leaves of one parent drawn side by side before wrapping to a new sub-row. */
    public const WRAP = 6;

    /** @var array{a: int, a_port: ?string, b: int, b_port: ?string}|null */
    #[Locked]
    public ?array $ends = null;

    #[Locked]
    public ?int $cableId = null;

    public array $form = [];

    #[Url(except: '')]
    public string $location = '';

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
        $devices = NetworkDevice::with('location')
            ->when(ctype_digit($this->location), fn ($query) => $query->whereIn('location_id', Location::findOrFail($this->location)->descendantsAndSelf()))
            ->orderBy('name')->get();
        $shown = array_flip($devices->modelKeys());
        $links = $topology->links()->filter(fn (array $link): bool => isset($shown[$link['a']->id], $shown[$link['b']->id]))->values();
        $wanPorts = DevicePort::where('wan', true)->whereIn('network_device_id', $devices->modelKeys())->orderBy('name')->get();

        return view('livewire.network-topology', self::layout($devices, $links, $wanPorts) + [
            'links' => $links,
            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
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

        // A device reached only through a peer of the same layer, such as an
        // access switch chained behind another one, hangs one layer below it.
        // Edge and core devices stay side by side: two linked cores are peers.
        $layer = [];
        foreach ($levels as $level => $devicesOfLevel) {
            foreach ($devicesOfLevel as $device) {
                $layer[$device->id] = $level;
            }
        }
        $byDistance = collect($layer)->keys()->sortBy(fn (int $id): array => [$distance[$id] ?? PHP_INT_MAX, $id])->values();
        foreach ($byDistance as $id) {
            $uplinks = array_filter($neighbours[$id] ?? [], fn (int $other): bool => isset($layer[$other]) && $layer[$other] < $layer[$id]);
            if ($uplinks !== [] || ! isset($distance[$id]) || $layer[$id] <= 2) {
                continue;
            }
            $closer = array_filter($neighbours[$id] ?? [], fn (int $other): bool => isset($layer[$other], $distance[$other]) && $distance[$other] < $distance[$id]);
            if ($closer !== []) {
                $layer[$id] = max($layer[$id], min(array_map(fn (int $other): int => $layer[$other], $closer)) + 1);
            }
        }
        $levels = [];
        foreach ($devices as $device) {
            if (isset($layer[$device->id])) {
                $levels[$layer[$device->id]][] = $device;
            }
        }
        ksort($levels);

        $column = 210;
        $rowHeight = 135;
        $subRow = 72;
        $top = $wanPorts->isNotEmpty() ? 175 : 70;

        // Each device hangs from its neighbour in the closest layer above, so a
        // branch of the network is drawn as a tree under its uplink.
        $levelOf = [];
        foreach ($levels as $level => $devicesOfLevel) {
            foreach ($devicesOfLevel as $device) {
                $levelOf[$device->id] = $level;
            }
        }
        $names = $devices->pluck('name', 'id')->all();
        $children = [];
        $roots = [];
        foreach ($levels as $level => $devicesOfLevel) {
            foreach ($devicesOfLevel as $device) {
                $parents = array_values(array_unique(array_filter($neighbours[$device->id] ?? [], fn (int $id): bool => isset($levelOf[$id]) && $levelOf[$id] < $level)));
                usort($parents, fn (int $left, int $right): int => [-$levelOf[$left], $names[$left]] <=> [-$levelOf[$right], $names[$right]]);
                if ($parents === []) {
                    $roots[] = $device->id;
                } else {
                    $children[$parents[0]][] = $device->id;
                }
            }
        }
        $byName = fn (array $ids): array => collect($ids)->sortBy(fn (int $id): string => mb_strtolower($names[$id]), SORT_NATURAL)->values()->all();

        // Leaves of one parent and one layer wrap into several sub-rows past WRAP.
        $extraRows = [];
        $widths = [];
        $blocks = [];
        $measure = function (int $id) use (&$measure, &$children, &$extraRows, &$widths, &$blocks, $levelOf, $column, $byName): int {
            $kids = $byName($children[$id] ?? []);
            $branches = array_values(array_filter($kids, fn (int $kid): bool => isset($children[$kid])));
            $leaves = array_values(array_filter($kids, fn (int $kid): bool => ! isset($children[$kid])));
            $blocks[$id] = array_map(fn (int $branch): array => ['branch' => $branch], $branches);
            $total = 0;
            foreach ($branches as $branch) {
                $total += $measure($branch);
            }
            foreach (collect($leaves)->groupBy(fn (int $leaf): int => $levelOf[$leaf])->sortKeys() as $level => $group) {
                $columns = min(self::WRAP, $group->count());
                $rows = (int) ceil($group->count() / self::WRAP);
                $extraRows[$level] = max($extraRows[$level] ?? 0, $rows - 1);
                $blocks[$id][] = ['leaves' => $group->values()->all(), 'columns' => $columns];
                $total += $columns * $column;
            }

            return $widths[$id] = max($column, $total);
        };
        $roots = collect($roots)->sortBy(fn (int $id): string => $levelOf[$id].' '.mb_strtolower($names[$id]), SORT_NATURAL)->values()->all();
        $treeWidth = array_sum(array_map($measure, $roots));

        $levelY = [];
        $y = $top;
        foreach (array_keys($levels) as $level) {
            $levelY[$level] = $y;
            $y += $rowHeight + ($extraRows[$level] ?? 0) * $subRow;
        }

        $isolatedColumns = min(self::WRAP + 2, count($isolated));
        $width = max(900, $treeWidth + 60, $isolatedColumns * $column + 60);
        $nodes = collect();
        $put = function (int $id, int $x, int $y) use ($nodes, $devices, $levelOf): void {
            $nodes->put($id, ['device' => $devices->firstWhere('id', $id), 'x' => $x, 'y' => $y, 'level' => $levelOf[$id] ?? 0]);
        };
        $place = function (int $id, float $left) use (&$place, &$blocks, &$widths, $put, $levelOf, $levelY, $column, $subRow): void {
            $put($id, (int) round($left + $widths[$id] / 2), $levelY[$levelOf[$id]]);
            $used = array_sum(array_map(fn (array $block): int => isset($block['branch']) ? $widths[$block['branch']] : $block['columns'] * $column, $blocks[$id]));
            $cursor = $left + ($widths[$id] - $used) / 2;
            foreach ($blocks[$id] as $block) {
                if (isset($block['branch'])) {
                    $place($block['branch'], $cursor);
                    $cursor += $widths[$block['branch']];

                    continue;
                }
                foreach ($block['leaves'] as $index => $leaf) {
                    $row = intdiv($index, self::WRAP);
                    $inRow = min(self::WRAP, count($block['leaves']) - $row * self::WRAP);
                    $offset = ($block['columns'] - $inRow) * $column / 2;
                    $put($leaf, (int) round($cursor + $offset + ($index % self::WRAP + .5) * $column), $levelY[$levelOf[$leaf]] + $row * $subRow);
                }
                $cursor += $block['columns'] * $column;
            }
        };
        $cursor = ($width - $treeWidth) / 2;
        foreach ($roots as $root) {
            $place($root, $cursor);
            $cursor += $widths[$root];
        }

        $isolatedY = null;
        if ($isolated !== []) {
            $isolatedY = $y - 30;
            $isolatedIds = $byName(array_map(fn (NetworkDevice $device): int => $device->id, $isolated));
            foreach ($isolatedIds as $index => $id) {
                $row = intdiv($index, $isolatedColumns);
                $inRow = min($isolatedColumns, count($isolatedIds) - $row * $isolatedColumns);
                $put($id, (int) round(($width - $inRow * $column) / 2 + ($index % $isolatedColumns + .5) * $column), $isolatedY + 60 + $row * $subRow);
            }
            $y = $isolatedY + 60 + (int) (ceil(count($isolatedIds) / $isolatedColumns) - 1) * $subRow + $rowHeight;
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
