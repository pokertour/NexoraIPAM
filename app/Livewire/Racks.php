<?php

namespace App\Livewire;

use App\Models\CableLink;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Rack;
use App\Models\RackItem;
use App\Support\RackCabling;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Racks extends Component
{
    /** Height of one rack unit on screen, in pixels. */
    public const UNIT = 24;

    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public ?string $deleting = null;

    #[Locked]
    public ?int $recordId = null;

    #[Url(except: '')]
    public string $location = '';

    public array $form = [];

    public function open(string $kind, ?int $id = null, ?int $rackId = null, ?int $position = null): void
    {
        Gate::authorize('edit-inventory');
        abort_unless(in_array($kind, ['rack', 'item', 'cable'], true), 404);
        $this->close();
        $this->editing = $kind;
        $this->recordId = $id;

        if ($kind === 'cable') {
            // For a new cable, $rackId carries the item the cable starts from.
            $cable = $id ? CableLink::findOrFail($id) : null;
            $this->form = [
                'a_rack_item_id' => $cable?->a_rack_item_id ?? ($rackId ? RackItem::findOrFail($rackId)->id : null), 'a_port' => $cable?->a_port ?? '',
                'b_rack_item_id' => $cable?->b_rack_item_id, 'b_port' => $cable?->b_port ?? '',
                'medium' => $cable?->medium ?? 'copper', 'label' => $cable?->label ?? '',
            ];

            return;
        }

        if ($kind === 'rack') {
            $rack = $id ? Rack::findOrFail($id) : null;
            $this->form = [
                'name' => $rack?->name ?? '', 'location_id' => $rack?->location_id ?? (ctype_digit($this->location) ? (int) $this->location : null),
                'units' => $rack?->units ?? 42, 'sort_order' => $rack?->sort_order ?? 0, 'description' => $rack?->description ?? '',
            ];

            return;
        }

        $item = $id ? RackItem::findOrFail($id) : null;
        $this->form = [
            'rack_id' => $item?->rack_id ?? $rackId, 'kind' => $item?->kind ?? 'device',
            'network_device_id' => $item?->network_device_id, 'label' => $item?->label ?? '',
            'position' => $item?->position ?? $position ?? 1, 'height' => $item?->height ?? 1,
        ];
    }

    public function close(): void
    {
        $this->reset('editing', 'deleting', 'recordId', 'form');
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->editing !== null, 409);

        DB::transaction(function (): void {
            if ($this->editing === 'rack') {
                $rack = $this->recordId ? Rack::lockForUpdate()->findOrFail($this->recordId) : new Rack;
                $this->form['location_id'] = filled($this->form['location_id'] ?? null) ? $this->form['location_id'] : null;
                $data = $this->validate([
                    'form.name' => ['required', 'string', 'max:100'],
                    'form.location_id' => ['nullable', 'integer', 'exists:locations,id'],
                    'form.units' => ['required', 'integer', 'between:1,60'],
                    'form.sort_order' => ['required', 'integer', 'between:0,999'],
                    'form.description' => ['nullable', 'string', 'max:255'],
                ])['form'];
                if ($rack->exists && $rack->items()->get()->contains(fn (RackItem $item): bool => $item->top() > (int) $data['units'])) {
                    throw ValidationException::withMessages(['form.units' => __('Des éléments occupent des unités au-dessus de cette hauteur.')]);
                }
                $rack->fill($data)->save();

                return;
            }

            if ($this->editing === 'cable') {
                $this->saveCable();

                return;
            }

            $item = $this->recordId ? RackItem::lockForUpdate()->findOrFail($this->recordId) : new RackItem;
            $device = ($this->form['kind'] ?? null) === 'device';
            if (! $device) {
                $this->form['network_device_id'] = null;
            }
            $data = $this->validate([
                'form.rack_id' => ['required', 'integer', 'exists:racks,id'],
                'form.kind' => ['required', Rule::in(array_keys(RackItem::KINDS))],
                'form.network_device_id' => [Rule::requiredIf($device), 'nullable', 'integer', 'exists:network_devices,id', Rule::unique('rack_items', 'network_device_id')->ignore($item)],
                'form.label' => [Rule::requiredIf(! $device), 'nullable', 'string', 'max:100'],
                'form.position' => ['required', 'integer', 'between:1,60'],
                'form.height' => ['required', 'integer', 'between:1,20'],
            ], ['form.network_device_id.unique' => __('Cet équipement est déjà placé dans une baie.')])['form'];

            $rack = Rack::lockForUpdate()->findOrFail($data['rack_id']);
            if (! $rack->fits((int) $data['position'], (int) $data['height'], $item->id)) {
                throw ValidationException::withMessages(['form.position' => __('Ces unités sont occupées ou dépassent la hauteur de la baie.')]);
            }
            $item->fill($data + ['label' => null])->save();
        }, attempts: 3);

        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Entrée enregistrée.'));
    }

    /** A port typed for one item does not belong to another. */
    public function updatedForm(mixed $value, string $key): void
    {
        if ($this->editing === 'cable' && in_array($key, ['a_rack_item_id', 'b_rack_item_id'], true)) {
            $this->form[$key[0].'_port'] = '';
        }
    }

    public function confirmDelete(string $kind, int $id): void
    {
        Gate::authorize('edit-inventory');
        abort_unless(in_array($kind, ['rack', 'item', 'cable'], true), 404);
        $this->close();
        $this->deleting = $kind;
        $this->recordId = match ($kind) {
            'rack' => Rack::findOrFail($id)->id,
            'item' => RackItem::findOrFail($id)->id,
            'cable' => CableLink::findOrFail($id)->id,
        };
    }

    public function delete(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->deleting !== null && $this->recordId, 409);
        if ($this->deleting === 'rack') {
            $rack = Rack::findOrFail($this->recordId);
            $rack->items()->get()->each->delete();
            $rack->delete();
        } elseif ($this->deleting === 'cable') {
            CableLink::findOrFail($this->recordId)->delete();
        } else {
            RackItem::findOrFail($this->recordId)->delete();
        }
        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Entrée supprimée.'));
    }

    /**
     * Validates and stores a cable documented by hand. On a device, the port
     * must be one of its physical ports; on a passive item it is free text.
     */
    private function saveCable(): void
    {
        $cable = $this->recordId ? CableLink::lockForUpdate()->findOrFail($this->recordId) : new CableLink;
        foreach (['a', 'b'] as $end) {
            $this->form[$end.'_port'] = trim((string) ($this->form[$end.'_port'] ?? '')) ?: null;
        }
        $this->form['label'] = trim((string) ($this->form['label'] ?? '')) ?: null;
        $ports = fn (string $end): ?array => ($item = RackItem::with('device.ports')->find($this->form[$end.'_rack_item_id'] ?? 0))?->device
            ? $item->device->ports->where('physical', true)->pluck('name')->all() : null;
        $portRules = fn (string $end): array => ['nullable', 'string', 'max:100', ...($ports($end) !== null ? [Rule::in($ports($end))] : [])];

        $data = $this->validate([
            'form.a_rack_item_id' => ['required', 'integer', 'exists:rack_items,id'],
            'form.b_rack_item_id' => ['required', 'integer', 'exists:rack_items,id', 'different:form.a_rack_item_id'],
            'form.a_port' => $portRules('a'),
            'form.b_port' => $portRules('b'),
            'form.medium' => ['required', Rule::in(array_keys(CableLink::MEDIA))],
            'form.label' => ['nullable', 'string', 'max:100'],
        ], [
            'form.b_rack_item_id.different' => __('Une liaison relie deux éléments différents.'),
            'form.a_port.in' => __('Choisissez un port de cet équipement.'),
            'form.b_port.in' => __('Choisissez un port de cet équipement.'),
        ])['form'];

        $cable->fill($data)->save();
    }

    /**
     * Cables between two items of the same rack, with the vertical centre of
     * each end in pixels from the top of the rack.
     *
     * @param  Collection<int, array<string, mixed>>  $cables
     * @return list<array{key: string, from: int, to: int, label: string, source: string}>
     */
    public static function rackLinks(Rack $rack, Collection $cables): array
    {
        $centre = fn (RackItem $item): int => (int) round(($rack->units - $item->top()) * self::UNIT + $item->height * self::UNIT / 2);
        $short = fn (array $end): string => $end['port'] !== null ? (preg_match('/(\d+)\D*$/', $end['port'], $match) ? $match[1] : $end['port']) : '?';

        $drawn = [];
        foreach ($cables as $cable) {
            [$a, $b] = $cable['ends'];
            if ($a['item']?->rack_id === $rack->id && $b['item']?->rack_id === $rack->id) {
                $drawn[] = [
                    'key' => $cable['key'], 'from' => $centre($a['item']), 'to' => $centre($b['item']), 'source' => $cable['source'],
                    'label' => $short($a).' ↔ '.$short($b),
                ];
            }
        }

        return $drawn;
    }

    /**
     * Racks grouped in rows, one per location: racks of a room stand side by
     * side, so cables between them can be drawn.
     *
     * @param  Collection<int, Rack>  $racks
     * @param  Collection<int, array<string, mixed>>  $cables
     * @return Collection<int, array{location: ?Location, racks: Collection<int, Rack>, cables: list<array{key: string, a: int, b: int, source: string, confirmed: bool, label: string}>}>
     */
    public static function rows(Collection $racks, Collection $cables): Collection
    {
        return $racks->groupBy(fn (Rack $rack): string => (string) $rack->location_id)
            ->sortBy(fn (Collection $group): string => $group->first()->location ? '0'.mb_strtolower($group->first()->location->path()) : '1')
            ->map(function (Collection $group) use ($cables): array {
                $ids = $group->pluck('id')->all();
                $between = $cables->filter(fn (array $cable): bool => in_array($cable['ends'][0]['item']?->rack_id, $ids, true)
                    && in_array($cable['ends'][1]['item']?->rack_id, $ids, true)
                    && $cable['ends'][0]['item']->rack_id !== $cable['ends'][1]['item']->rack_id);

                return [
                    'location' => $group->first()->location,
                    'racks' => $group->values(),
                    'cables' => $between->map(fn (array $cable): array => [
                        'key' => $cable['key'], 'a' => $cable['ends'][0]['item']->id, 'b' => $cable['ends'][1]['item']->id,
                        'source' => $cable['source'], 'confirmed' => $cable['confirmed'],
                        'label' => collect($cable['ends'])->map(fn (array $end): string => $end['name'].($end['port'] ? ' '.$end['port'] : ''))->implode(' ↔ '),
                    ])->values()->all(),
                ];
            })->values();
    }

    public function render(RackCabling $cabling): View
    {
        $racks = Rack::with(['location.parent.parent', 'items.device.ports' => fn ($query) => $query->where('physical', true)])
            ->when(ctype_digit($this->location), fn ($query) => $query->whereIn('location_id', Location::findOrFail($this->location)->descendantsAndSelf()))
            ->orderBy('sort_order')->orderBy('name')->get();
        $cables = $cabling->cables();

        return view('livewire.racks', [
            'racks' => $racks,
            'rows' => self::rows($racks, $cables),
            'cables' => $cables,
            'cableItems' => $this->editing === 'cable' ? RackItem::with(['rack', 'device.ports' => fn ($query) => $query->where('physical', true)])->get()
                ->sortBy(fn (RackItem $item): string => mb_strtolower($item->rack->name).sprintf('%03d', 999 - $item->position)) : collect(),
            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
            'devices' => $this->editing === 'item'
                ? NetworkDevice::whereDoesntHave('rackItem', fn ($query) => $query->whereKeyNot($this->recordId ?? 0))->orderBy('name')->get()
                : collect(),
            'allRacks' => $this->editing === 'item' ? Rack::orderBy('name')->get() : collect(),
        ])->layout('components.layouts.app', ['title' => __('Baies')]);
    }
}
