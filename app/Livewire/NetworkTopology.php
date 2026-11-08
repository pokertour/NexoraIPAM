<?php

namespace App\Livewire;

use App\Models\NetworkDevice;
use App\Support\Topology;
use Illuminate\View\View;
use Livewire\Component;

class NetworkTopology extends Component
{
    public const WIDTH = 1000;

    public const HEIGHT = 620;

    public function render(Topology $topology): View
    {
        $devices = NetworkDevice::with('location')->orderBy('name')->get();
        $links = $topology->links();
        $count = max(1, $devices->count());
        $radius = $count === 1 ? 0 : min(self::WIDTH, self::HEIGHT) / 2 - 90;

        $nodes = $devices->values()->mapWithKeys(function (NetworkDevice $device, int $index) use ($count, $radius): array {
            $angle = 2 * M_PI * $index / $count - M_PI / 2;

            return [$device->id => [
                'device' => $device,
                'x' => (int) round(self::WIDTH / 2 + $radius * cos($angle)),
                'y' => (int) round(self::HEIGHT / 2 + $radius * sin($angle)),
            ]];
        });

        return view('livewire.network-topology', [
            'nodes' => $nodes,
            'links' => $links,
        ])->layout('components.layouts.app', ['title' => __('Topologie')]);
    }
}
