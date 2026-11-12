<?php

namespace App\Livewire;

use App\Models\CableLink;
use App\Models\NetworkDevice;
use App\Models\RackItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The form of a cable documented by hand, opened from the racks, the
 * topology or a device port. Each end is a rack item, or a device that is
 * not racked; a racked device is always stored through its rack item, like
 * the cables of the racks page.
 */
class CableEditor extends Component
{
    #[Locked]
    public bool $open = false;

    #[Locked]
    public ?int $cableId = null;

    /** @var array{a?: string, a_port?: string, b?: string, b_port?: string, medium?: string, label?: string} */
    public array $form = [];

    /**
     * Opens the form, for an existing cable or a new one. Ends are given as
     * "item:ID" or "device:ID", with an optional port name.
     */
    #[On('open-cable-editor')]
    public function openEditor(?int $cable = null, ?string $from = null, ?string $fromPort = null, ?string $to = null, ?string $toPort = null): void
    {
        Gate::authorize('edit-inventory');
        $this->resetValidation();
        $existing = $cable ? CableLink::findOrFail($cable) : null;
        $this->cableId = $existing?->id;
        $this->form = [
            'a' => $existing ? self::key($existing->a_rack_item_id, $existing->a_network_device_id) : self::normalize($from),
            'a_port' => $existing?->a_port ?? ($fromPort ?? ''),
            'b' => $existing ? self::key($existing->b_rack_item_id, $existing->b_network_device_id) : self::normalize($to),
            'b_port' => $existing?->b_port ?? ($toPort ?? ''),
            'medium' => $existing?->medium ?? 'copper',
            'label' => $existing?->label ?? '',
        ];
        $this->open = true;
    }

    public function close(): void
    {
        $this->reset('open', 'cableId', 'form');
        $this->resetValidation();
    }

    /** A port chosen for one end does not belong to another. */
    public function updatedForm(mixed $value, string $key): void
    {
        if (in_array($key, ['a', 'b'], true)) {
            $this->form[$key.'_port'] = '';
        }
    }

    public function save(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->open, 409);
        foreach (['a_port', 'b_port', 'label'] as $field) {
            $this->form[$field] = trim((string) ($this->form[$field] ?? ''));
        }
        $endpoints = self::endpoints();
        $ports = fn (string $end): ?array => ($device = $endpoints->get($this->form[$end] ?? '')['device'] ?? null)
            ? $device->ports->where('physical', true)->pluck('name')->all() : null;
        $portRules = fn (string $end): array => ['nullable', 'string', 'max:100', ...($ports($end) !== null ? [Rule::in($ports($end))] : [])];

        $data = $this->validate([
            'form.a' => ['required', 'string', Rule::in($endpoints->keys()->all())],
            'form.b' => ['required', 'string', Rule::in($endpoints->keys()->all()), 'different:form.a'],
            'form.a_port' => $portRules('a'),
            'form.b_port' => $portRules('b'),
            'form.medium' => ['required', Rule::in(array_keys(CableLink::MEDIA))],
            'form.label' => ['nullable', 'string', 'max:100'],
        ], [
            'form.a.required' => __('Choisissez un élément ou un équipement.'),
            'form.b.required' => __('Choisissez un élément ou un équipement.'),
            'form.b.different' => __('Une liaison relie deux éléments différents.'),
            'form.a_port.in' => __('Choisissez un port de cet équipement.'),
            'form.b_port.in' => __('Choisissez un port de cet équipement.'),
        ])['form'];

        $end = function (string $side) use ($data): array {
            [$type, $id] = explode(':', $data[$side]);

            return [
                $side.'_rack_item_id' => $type === 'item' ? (int) $id : null,
                $side.'_network_device_id' => $type === 'device' ? (int) $id : null,
                $side.'_port' => $data[$side.'_port'] !== '' ? $data[$side.'_port'] : null,
            ];
        };
        $cable = $this->cableId ? CableLink::findOrFail($this->cableId) : new CableLink;
        $cable->fill($end('a') + $end('b') + ['medium' => $data['medium'], 'label' => $data['label'] !== '' ? $data['label'] : null])->save();

        $this->close();
        $this->dispatch('cable-saved');
        $this->dispatch('nexora-toast', tone: 'success', message: __('Liaison enregistrée.'));
    }

    /** The key of an end: its rack item, or the device when it is not racked. */
    public static function key(?int $itemId, ?int $deviceId): string
    {
        return $itemId ? 'item:'.$itemId : ($deviceId ? self::normalize('device:'.$deviceId) : '');
    }

    /** A racked device is reached through its rack item. */
    private static function normalize(?string $key): string
    {
        if ($key === null || ! preg_match('/^(item|device):(\d+)$/', $key, $matches)) {
            return '';
        }
        if ($matches[1] === 'device' && ($itemId = RackItem::where('network_device_id', (int) $matches[2])->value('id'))) {
            return 'item:'.$itemId;
        }

        return $key;
    }

    /**
     * Every possible end: rack items first, then devices that are not racked.
     *
     * @return Collection<string, array{label: string, group: string, device: ?NetworkDevice}>
     */
    public static function endpoints(): Collection
    {
        $items = RackItem::with(['rack', 'device.ports'])->get()
            ->sortBy(fn (RackItem $item): string => mb_strtolower($item->rack->name).' '.str_pad((string) (100 - $item->position), 3, '0', STR_PAD_LEFT), SORT_NATURAL)
            ->mapWithKeys(fn (RackItem $item): array => ['item:'.$item->id => [
                'label' => $item->rack->name.' · U'.$item->position.' · '.$item->displayName(), 'group' => __('Dans une baie'), 'device' => $item->device,
            ]]);
        $devices = NetworkDevice::with('ports')->whereDoesntHave('rackItem')->orderBy('name')->get()
            ->mapWithKeys(fn (NetworkDevice $device): array => ['device:'.$device->id => [
                'label' => $device->name.' · '.$device->host, 'group' => __('Hors baie'), 'device' => $device,
            ]]);

        return $items->toBase()->merge($devices->toBase());
    }

    public function render(): View
    {
        return view('livewire.cable-editor', ['endpoints' => $this->open ? self::endpoints() : collect()]);
    }
}
