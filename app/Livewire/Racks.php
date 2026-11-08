<?php

namespace App\Livewire;

use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Rack;
use App\Models\RackItem;
use App\Support\Topology;
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
        abort_unless(in_array($kind, ['rack', 'item'], true), 404);
        $this->close();
        $this->editing = $kind;
        $this->recordId = $id;

        if ($kind === 'rack') {
            $rack = $id ? Rack::findOrFail($id) : null;
            $this->form = [
                'name' => $rack?->name ?? '', 'location_id' => $rack?->location_id ?? (ctype_digit($this->location) ? (int) $this->location : null),
                'units' => $rack?->units ?? 42, 'description' => $rack?->description ?? '',
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
                    'form.description' => ['nullable', 'string', 'max:255'],
                ])['form'];
                if ($rack->exists && $rack->items()->get()->contains(fn (RackItem $item): bool => $item->top() > (int) $data['units'])) {
                    throw ValidationException::withMessages(['form.units' => __('Des éléments occupent des unités au-dessus de cette hauteur.')]);
                }
                $rack->fill($data)->save();

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

    public function confirmDelete(string $kind, int $id): void
    {
        Gate::authorize('edit-inventory');
        abort_unless(in_array($kind, ['rack', 'item'], true), 404);
        $this->close();
        $this->deleting = $kind;
        $this->recordId = ($kind === 'rack' ? Rack::findOrFail($id) : RackItem::findOrFail($id))->id;
    }

    public function delete(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->deleting !== null && $this->recordId, 409);
        if ($this->deleting === 'rack') {
            $rack = Rack::findOrFail($this->recordId);
            $rack->items()->get()->each->delete();
            $rack->delete();
        } else {
            RackItem::findOrFail($this->recordId)->delete();
        }
        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: __('Entrée supprimée.'));
    }

    /**
     * Links between two devices of the same rack, with the vertical centre of
     * each end in pixels from the top of the rack.
     *
     * @param  Collection<int, array<string, mixed>>  $links
     * @return list<array{from: int, to: int, label: string, source: string}>
     */
    public static function rackLinks(Rack $rack, Collection $links): array
    {
        $centres = [];
        foreach ($rack->items as $item) {
            if ($item->network_device_id) {
                $centres[$item->network_device_id] = (int) round(($rack->units - $item->top()) * self::UNIT + $item->height * self::UNIT / 2);
            }
        }

        $drawn = [];
        foreach ($links as $link) {
            if (isset($centres[$link['a']->id], $centres[$link['b']->id])) {
                $drawn[] = [
                    'from' => $centres[$link['a']->id], 'to' => $centres[$link['b']->id], 'source' => $link['source'],
                    'label' => $link['a_port']->shortName().' ↔ '.($link['b_port']?->shortName() ?? '?'),
                ];
            }
        }

        return $drawn;
    }

    public function render(Topology $topology): View
    {
        $racks = Rack::with(['location.parent.parent', 'items.device.ports' => fn ($query) => $query->where('physical', true)])
            ->when(ctype_digit($this->location), fn ($query) => $query->whereIn('location_id', Location::findOrFail($this->location)->descendantsAndSelf()))
            ->orderBy('name')->get();
        $links = $topology->links();

        return view('livewire.racks', [
            'racks' => $racks,
            'links' => $links,
            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
            'devices' => $this->editing === 'item'
                ? NetworkDevice::whereDoesntHave('rackItem', fn ($query) => $query->whereKeyNot($this->recordId ?? 0))->orderBy('name')->get()
                : collect(),
            'allRacks' => $this->editing === 'item' ? Rack::orderBy('name')->get() : collect(),
        ])->layout('components.layouts.app', ['title' => __('Baies')]);
    }
}
