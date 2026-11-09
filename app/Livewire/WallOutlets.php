<?php

namespace App\Livewire;

use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\WallOutlet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class WallOutlets extends Component
{
    use WithPagination;

    /** @var array<string, string> */
    public const STATES = ['' => 'Toutes', 'assigned' => 'Brassées', 'free' => 'Libres'];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $location = '';

    #[Url(except: '')]
    public string $state = '';

    #[Locked]
    public ?string $editing = null;

    #[Locked]
    public bool $deleting = false;

    #[Locked]
    public ?int $recordId = null;

    public array $form = [];

    /** @var list<int|string> */
    public array $selected = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'location', 'state'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    public function open(string $kind, ?int $id = null): void
    {
        Gate::authorize('edit-inventory');
        abort_unless(in_array($kind, ['batch', 'outlet'], true), 404);
        $this->close();
        $this->editing = $kind;
        $location = ctype_digit($this->location) ? (int) $this->location : null;

        if ($kind === 'batch') {
            $this->form = ['location_id' => $location, 'prefix' => '', 'start' => 1, 'end' => 24, 'width' => 0, 'suffix' => '', 'description' => ''];

            return;
        }

        $outlet = $id ? WallOutlet::findOrFail($id) : null;
        $this->recordId = $outlet?->id;
        $this->form = [
            'name' => $outlet?->name ?? '', 'location_id' => $outlet?->location_id ?? $location, 'description' => $outlet?->description ?? '',
            'network_device_id' => $outlet?->network_device_id, 'port' => $outlet?->port ?? '',
        ];
    }

    public function close(): void
    {
        $this->reset('editing', 'deleting', 'recordId', 'form');
        $this->resetValidation();
    }

    /** A port belongs to one device: choosing another device clears it. */
    public function updatedFormNetworkDeviceId(): void
    {
        $this->form['port'] = '';
    }

    public function save(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->editing !== null, 409);
        $this->form['location_id'] = filled($this->form['location_id'] ?? null) ? $this->form['location_id'] : null;

        $message = $this->editing === 'batch' ? $this->saveBatch() : $this->saveOutlet();

        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: $message);
    }

    /** Creates a series of outlets, skipping the names that already exist in the location. */
    private function saveBatch(): string
    {
        $data = $this->validate([
            'form.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'form.prefix' => ['nullable', 'string', 'max:20'],
            'form.start' => ['required', 'integer', 'between:0,99999'],
            'form.end' => ['required', 'integer', 'between:0,99999', 'gte:form.start'],
            'form.width' => ['required', 'integer', 'between:0,6'],
            'form.suffix' => ['nullable', 'string', 'max:20'],
            'form.description' => ['nullable', 'string', 'max:255'],
        ], ['form.end.gte' => __('La fin doit être supérieure ou égale au début.')])['form'];
        if ((int) $data['end'] - (int) $data['start'] + 1 > WallOutlet::BATCH_LIMIT) {
            throw ValidationException::withMessages(['form.end' => __('Un lot crée au plus :count prises.', ['count' => WallOutlet::BATCH_LIMIT])]);
        }

        $names = WallOutlet::batchNames(trim((string) $data['prefix']), (int) $data['start'], (int) $data['end'], (int) $data['width'], trim((string) $data['suffix']));
        $location = $data['location_id'] === null ? null : (int) $data['location_id'];

        $created = DB::transaction(function () use ($names, $location, $data): int {
            $existing = WallOutlet::where('location_id', $location)->whereIn('name', $names)->pluck('name')->all();
            $missing = array_values(array_diff($names, $existing));
            foreach ($missing as $name) {
                WallOutlet::create(['location_id' => $location, 'name' => $name, 'description' => $data['description'] ?: null]);
            }

            return count($missing);
        }, attempts: 3);

        $skipped = count($names) - $created;

        return trans_choice(':count prise créée.|:count prises créées.', $created)
            .($skipped > 0 ? ' '.trans_choice(':count existait déjà.|:count existaient déjà.', $skipped) : '');
    }

    private function saveOutlet(): string
    {
        $outlet = $this->recordId ? WallOutlet::findOrFail($this->recordId) : new WallOutlet;
        $this->form['name'] = trim((string) ($this->form['name'] ?? ''));
        $this->form['network_device_id'] = filled($this->form['network_device_id'] ?? null) ? $this->form['network_device_id'] : null;
        $this->form['port'] = $this->form['network_device_id'] ? (trim((string) ($this->form['port'] ?? '')) ?: null) : null;
        $ports = $this->form['network_device_id'] ? NetworkDevice::find($this->form['network_device_id'])?->ports()->where('physical', true)->pluck('name')->all() : null;

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:50', Rule::unique('wall_outlets', 'name')->where('location_id', $this->form['location_id'])->ignore($outlet)],
            'form.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'form.description' => ['nullable', 'string', 'max:255'],
            'form.network_device_id' => ['nullable', 'integer', 'exists:network_devices,id'],
            'form.port' => [Rule::requiredIf($this->form['network_device_id'] !== null), 'nullable', 'string', 'max:100',
                ...($ports !== null ? [Rule::in($ports)] : []),
                Rule::unique('wall_outlets', 'port')->where('network_device_id', $this->form['network_device_id'])->ignore($outlet)],
        ], [
            'form.name.unique' => __('Cette prise existe déjà à cet emplacement.'),
            'form.port.in' => __('Choisissez un port de cet équipement.'),
            'form.port.unique' => __('Ce port est déjà brassé sur une autre prise.'),
        ])['form'];

        $outlet->fill(['description' => filled($data['description'] ?? null) ? trim($data['description']) : null] + $data)->save();

        return __('Entrée enregistrée.');
    }

    public function confirmDelete(?int $id = null): void
    {
        Gate::authorize('edit-inventory');
        $this->close();
        $this->recordId = $id ? WallOutlet::findOrFail($id)->id : null;
        abort_if($this->recordId === null && $this->selected === [], 409);
        $this->deleting = true;
    }

    /** Deletes one outlet, or the selected ones. */
    public function delete(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->deleting, 409);
        $ids = $this->recordId ? [$this->recordId] : array_map('intval', $this->selected);
        $outlets = WallOutlet::whereKey($ids)->get();
        DB::transaction(fn () => $outlets->each->delete());

        $this->selected = array_values(array_diff($this->selected, $outlets->modelKeys()));
        $this->close();
        $this->dispatch('nexora-toast', tone: 'success', message: trans_choice(':count prise supprimée.|:count prises supprimées.', $outlets->count()));
    }

    /** Unpatches the selected outlets. */
    public function unassignSelected(): void
    {
        Gate::authorize('edit-inventory');
        $outlets = WallOutlet::whereKey(array_map('intval', $this->selected))->whereNotNull('network_device_id')->get();
        DB::transaction(fn () => $outlets->each(fn (WallOutlet $outlet) => $outlet->update(['network_device_id' => null, 'port' => null])));

        $this->dispatch('nexora-toast', tone: 'success', message: trans_choice(':count prise débrassée.|:count prises débrassées.', $outlets->count()));
    }

    public function render(): View
    {
        $search = mb_substr(trim($this->search), 0, 100);
        $filtered = WallOutlet::query()
            ->when(ctype_digit($this->location), fn (Builder $query) => $query->whereIn('location_id', Location::findOrFail($this->location)->descendantsAndSelf()))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereLike('name', '%'.$search.'%')->orWhereLike('description', '%'.$search.'%')->orWhereLike('port', '%'.$search.'%')
                ->orWhereHas('device', fn (Builder $query) => $query->whereLike('name', '%'.$search.'%'))));

        $counts = [
            '' => (clone $filtered)->count(),
            'assigned' => (clone $filtered)->whereNotNull('network_device_id')->count(),
            'free' => (clone $filtered)->whereNull('network_device_id')->count(),
        ];
        $outlets = $filtered
            ->when($this->state === 'assigned', fn (Builder $query) => $query->whereNotNull('network_device_id'))
            ->when($this->state === 'free', fn (Builder $query) => $query->whereNull('network_device_id'))
            ->with(['location.parent.parent', 'device.ports' => fn ($query) => $query->where('physical', true)])
            ->get()
            ->sortBy(fn (WallOutlet $outlet): string => mb_strtolower($outlet->location?->path() ?? '~').' '.$outlet->name, SORT_NATURAL)
            ->values();
        $page = $this->getPage();
        $perPage = 50;

        $device = $this->editing === 'outlet' && filled($this->form['network_device_id'] ?? null)
            ? NetworkDevice::with(['ports' => fn ($query) => $query->where('physical', true)])->find($this->form['network_device_id']) : null;

        return view('livewire.wall-outlets', [
            'outlets' => new LengthAwarePaginator($outlets->forPage($page, $perPage)->values(), $outlets->count(), $perPage, $page, ['path' => request()->url(), 'pageName' => 'page']),
            'counts' => $counts,
            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
            'devices' => $this->editing === 'outlet' ? NetworkDevice::orderBy('name')->get(['id', 'name', 'host']) : collect(),
            'ports' => $device?->ports->sortBy('name', SORT_NATURAL)->values() ?? collect(),
            'taken' => $device ? WallOutlet::where('network_device_id', $device->id)->whereKeyNot($this->recordId ?? 0)->pluck('name', 'port') : collect(),
            'preview' => $this->editing === 'batch' ? $this->preview() : null,
        ])->layout('components.layouts.app', ['title' => __('Prises murales')]);
    }

    /** @return array{count: int, first: string, last: string}|null */
    private function preview(): ?array
    {
        $start = $this->form['start'] ?? null;
        $end = $this->form['end'] ?? null;
        if (! is_numeric($start) || ! is_numeric($end) || (int) $end < (int) $start || (int) $end - (int) $start >= WallOutlet::BATCH_LIMIT) {
            return null;
        }
        $width = is_numeric($this->form['width'] ?? null) ? max(0, min(6, (int) $this->form['width'])) : 0;
        $names = WallOutlet::batchNames(trim((string) ($this->form['prefix'] ?? '')), (int) $start, (int) $end, $width, trim((string) ($this->form['suffix'] ?? '')));

        return ['count' => count($names), 'first' => $names[0], 'last' => end($names)];
    }
}
