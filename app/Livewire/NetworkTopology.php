<?php

namespace App\Livewire;

use App\Models\CableLink;
use App\Models\NetworkDevice;
use App\Models\RackItem;
use App\Support\Topology;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NetworkTopology extends Component
{
    public const WIDTH = 1000;

    public const HEIGHT = 620;

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
