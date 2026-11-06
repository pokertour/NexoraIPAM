<?php

namespace App\Livewire;

use App\Models\IpAddress;
use App\Models\Location;
use App\Models\Subnet;
use App\Models\Vlan;
use App\Support\AddressAvailability;
use App\Support\DiscoveryQueue;
use App\Support\IpNetwork;
use App\Support\MacAddress;
use App\Support\SubnetSpace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Inventory extends Component
{
    use WithPagination;

    public const SECTIONS = [
        'locations' => ['Emplacements', 'emplacement', 'Organisez votre infrastructure par agence, bureau et étage.'],
        'subnets' => ['Sous-réseaux', 'sous-réseau', 'Définissez les plages IPv4 et IPv6 de votre infrastructure.'],
        'addresses' => ['Adresses IP', 'adresse IP', 'Suivez les affectations et réservations de chaque réseau.'],
        'vlans' => ['VLAN', 'VLAN', 'Organisez les segments logiques de votre infrastructure.'],
    ];

    #[Locked]
    public string $section;

    #[Locked]
    public ?int $recordId = null;

    #[Locked]
    public bool $editing = false;

    #[Locked]
    public bool $deleting = false;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $location = '';

    #[Locked]
    public ?int $scanId = null;

    #[Url(except: '')]
    public string $subnet = '';

    #[Url(except: '')]
    public string $space = '';

    #[Locked]
    public ?int $allocationParent = null;

    public array $form = [];

    public function mount(string $section): void
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        $this->section = $section;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocation(): void
    {
        $this->resetPage();
    }

    public function updatedSubnet(): void
    {
        $this->resetPage();
    }

    public function openAddress(SubnetSpace $space, int $subnetId, ?string $address = null): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->section === 'addresses', 404);
        $subnet = Subnet::findOrFail($subnetId);
        if ($address !== null) {
            $address = IpNetwork::address($address);
            if (! $address || ! IpNetwork::assignable($subnet->cidr, $address) || $space->delegated($subnet, $address)) {
                $this->dispatch('nexora-toast', message: __('Cette adresse ne peut pas être attribuée dans ce réseau.'), tone: 'error');

                return;
            }
            $record = $subnet->addresses()->where('address', $address)->first();
            if ($record) {
                $this->open($record->id);

                return;
            }
        }

        $this->open();
        $this->form['subnet_id'] = $subnet->id;
        $this->form['address'] = $address ?? '';
    }

    public function openAllocation(int $parentId, string $cidr, SubnetSpace $space): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->section === 'subnets', 404);
        $parent = Subnet::findOrFail($parentId);
        $cidr = IpNetwork::normalize(trim($cidr));
        if ($cidr === null || ! $space->canAllocate($parent, $cidr)) {
            $this->dispatch('nexora-toast', message: __('Ce bloc n’est plus disponible.'), tone: 'error');

            return;
        }

        $this->open();
        $this->allocationParent = $parent->id;
        $this->form['cidr'] = $cidr;
        $this->form['location_id'] = $parent->location_id;
        $this->form['vlan_id'] = $parent->vlan_id;
    }

    public function confirmScan(int $id): void
    {
        Gate::authorize('administer');
        abort_unless($this->section === 'subnets', 404);
        $this->close();
        $this->scanId = Subnet::findOrFail($id)->id;
    }

    public function scan(DiscoveryQueue $queue): void
    {
        Gate::authorize('administer');
        abort_unless($this->section === 'subnets' && $this->scanId, 409);

        $message = $queue->enqueue($this->scanId);

        $this->close();
        $this->dispatch('nexora-toast', message: $message ?? __('Découverte mise en file d’attente.'), tone: $message ? 'info' : 'success');
    }

    public function updatedFormKind(): void
    {
        $this->form['parent_id'] = null;
        $this->resetValidation('form.parent_id');
    }

    public function open(?int $id = null): void
    {
        Gate::authorize('edit-inventory');
        $this->close();
        $this->recordId = $id;
        $this->form = $id === null ? [
            'name' => '', 'cidr' => '', 'vlan_id' => null, 'description' => '', 'number' => '',
            'discovery_interval' => null, 'location_id' => null, 'kind' => 'agency', 'parent_id' => null, 'subnet_id' => null, 'address' => '', 'hostname' => '', 'status' => 'used',
        ] : $this->query()->findOrFail($id)->getAttributes();
        $this->editing = true;
    }

    public function close(): void
    {
        $this->reset('editing', 'deleting', 'recordId', 'form', 'scanId', 'allocationParent');
        $this->resetValidation();
    }

    public function confirmDelete(int $id): void
    {
        Gate::authorize('edit-inventory');
        $this->close();
        $this->recordId = $this->query()->findOrFail($id)->id;
        $this->deleting = true;
    }

    public function delete(): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->deleting && $this->recordId, 409);

        $error = DB::transaction(function (): ?string {
            $record = $this->query()->lockForUpdate()->findOrFail($this->recordId);
            if ($record instanceof Subnet && ($record->scanPending() || $record->addresses()->exists() || $record->childSubnets()->exists())) {
                return __('Ce réseau a des adresses, des sous-réseaux ou une découverte en cours. Traitez-les avant de le supprimer.');
            }
            if ($record instanceof Location && ($record->children()->exists() || $record->subnets()->exists() || $record->vlans()->exists())) {
                return __('Déplacez l’inventaire et supprimez les emplacements enfants avant de supprimer cet emplacement.');
            }
            $record->delete();

            return null;
        }, attempts: 3);

        $this->close();
        $this->resetPage();
        if ($error) {
            $this->addError('delete', $error);
        }
        $this->dispatch('nexora-toast', message: $error ?? __('Entrée supprimée.'), tone: $error ? 'error' : 'success');
    }

    public function save(SubnetSpace $space): void
    {
        Gate::authorize('edit-inventory');
        abort_unless($this->editing, 409);

        DB::transaction(function () use ($space): void {
            $record = $this->recordId === null
                ? $this->query()->getModel()
                : $this->query()->lockForUpdate()->findOrFail($this->recordId);

            $rules = ['description' => ['nullable', 'string', 'max:255']];
            if ($record instanceof Subnet || $record instanceof Vlan) {
                $this->form['location_id'] = filled($this->form['location_id'] ?? null) ? $this->form['location_id'] : null;
                $rules['location_id'] = ['nullable', 'integer', 'exists:locations,id'];
            }

            if ($record instanceof Subnet) {
                if (($this->form['vlan_id'] ?? null) === '') {
                    $this->form['vlan_id'] = null;
                }

                $cidr = $this->form['cidr'] ?? null;
                $this->form['cidr'] = is_string($cidr) ? IpNetwork::normalize(trim($cidr)) : null;
                $rules += [
                    'cidr' => ['required', Rule::unique('subnets', 'cidr')->where('scope_id', $this->scopeId())->ignore($record)],
                    'name' => ['required', 'string', 'max:100'],
                    'vlan_id' => ['nullable', 'integer', 'exists:vlans,id'],
                ];
            } elseif ($record instanceof IpAddress) {
                $address = $this->form['address'] ?? null;
                $this->form['address'] = is_string($address) ? IpNetwork::address(trim($address)) : null;
                $rules += [
                    'subnet_id' => ['required', 'integer', 'exists:subnets,id'],
                    'address' => ['required', 'ip'],
                    'hostname' => ['nullable', 'string', 'max:253'],
                    'status' => ['required', Rule::in(array_keys(IpAddress::STATUSES))],
                ];
            } elseif ($record instanceof Vlan) {
                $rules += [
                    'number' => ['required', 'integer', 'between:1,4094', Rule::unique('vlans', 'number')->where('scope_id', $this->scopeId())->ignore($record)],
                    'name' => ['required', 'string', 'max:100'],
                ];
            } else {
                $this->form['parent_id'] = filled($this->form['parent_id'] ?? null) ? $this->form['parent_id'] : null;
                if ($record->exists) {
                    $this->form['kind'] = $record->kind;
                    $this->form['parent_id'] = $record->parent_id;
                }

                $rules += [
                    'name' => ['required', 'string', 'max:100'],
                    'kind' => ['required', Rule::in(array_keys(Location::KINDS))],
                    'parent_id' => ['nullable', 'integer', 'exists:locations,id'],
                ];
                if (Gate::allows('administer')) {
                    $this->form['discovery_interval'] = filled($this->form['discovery_interval'] ?? null) ? $this->form['discovery_interval'] : null;
                    $rules['discovery_interval'] = ['nullable', 'integer', 'between:1,10080'];
                }
            }

            $data = $this->validate(collect($rules)->mapWithKeys(fn (array $rule, string $field): array => ['form.'.$field => $rule])->all(), [
                'form.cidr.required' => __('Saisissez un réseau valide au format CIDR (IPv4 ou IPv6).'),
                'form.address.required' => __('Saisissez une adresse IPv4 ou IPv6 valide.'),
            ])['form'];
            $data = array_intersect_key($data, $rules);

            if ($record instanceof IpAddress) {
                $subnet = Subnet::lockForUpdate()->findOrFail($data['subnet_id']);
                if (! IpNetwork::assignable($subnet->cidr, $data['address']) || $space->delegated($subnet, $data['address'])) {
                    throw ValidationException::withMessages(['form.address' => __('Cette adresse n’est pas attribuable dans ce sous-réseau.')]);
                }

                $this->validate(['form.address' => [
                    Rule::unique('ip_addresses', 'address')->where('subnet_id', $subnet->id)->ignore($record),
                ]]);
            }

            if ($record instanceof Subnet && $record->exists && $record->cidr !== $data['cidr'] && $record->addresses()->exists()) {
                throw ValidationException::withMessages(['form.cidr' => __('Déplacez les adresses existantes avant de modifier la plage CIDR.')]);
            }

            if ($record instanceof Location) {
                $parent = isset($data['parent_id']) ? Location::lockForUpdate()->findOrFail($data['parent_id']) : null;
                if ($parent?->kind !== Location::PARENT_KINDS[$data['kind']]) {
                    throw ValidationException::withMessages(['form.parent_id' => __('Sélectionnez un parent correspondant à ce type d’emplacement.')]);
                }
            }

            if ($record instanceof Subnet) {
                $this->validateSubnetLocation($record, $data);
                if ($this->allocationParent !== null) {
                    $parent = Subnet::lockForUpdate()->findOrFail($this->allocationParent);
                    if ($parent->scanPending() || $parent->location_id !== $this->locationId($data) || ! $space->canAllocate($parent, $data['cidr'])) {
                        throw ValidationException::withMessages(['form.cidr' => __('Ce bloc est occupé, hors de l’emplacement du parent ou le parent est en cours de découverte.')]);
                    }
                    $record->parent_subnet_id = $parent->id;
                }
            }

            if ($record instanceof Vlan && $record->exists && $record->location_id !== $this->locationId($data)) {
                $this->validateVlanLocation($record, $this->locationId($data));
            }

            if ($record instanceof Location && array_key_exists('discovery_interval', $data)) {
                $interval = $data['discovery_interval'] === null ? null : (int) $data['discovery_interval'];
                if ($interval !== $record->discovery_interval) {
                    $record->discovery_interval = $interval;
                    $record->next_discovery_at = $interval === null ? null : now()->addMinutes($interval);
                }
            }

            $record->fill($data);
            if ($record instanceof IpAddress && $record->exists && $record->isDirty(['address', 'subnet_id'])) {
                $record->forceFill(['last_probe_at' => null, 'last_seen_at' => null, 'resolved_hostname' => null, 'mac_address' => null, 'last_probe_responded' => null]);
            }
            $record->save();
        }, attempts: 3);

        $this->close();
        $this->dispatch('nexora-toast', message: __('Entrée enregistrée.'), tone: 'success');
    }

    private function scopeId(): int
    {
        $location = $this->form['location_id'] ?? null;

        return is_numeric($location) ? (int) $location : 0;
    }

    private function locationId(array $data): ?int
    {
        return isset($data['location_id']) ? (int) $data['location_id'] : null;
    }

    private function validateSubnetLocation(Subnet $subnet, array $data): void
    {
        $locationId = $this->locationId($data);
        if (isset($data['vlan_id'])) {
            $vlan = Vlan::lockForUpdate()->findOrFail($data['vlan_id']);
            if ($vlan->location_id && ! ($locationId && Location::findOrFail($locationId)->belongsToLocation($vlan->location_id))) {
                throw ValidationException::withMessages(['form.vlan_id' => __('Sélectionnez un VLAN disponible à cet emplacement.')]);
            }
        }

        if ($subnet->exists && ($subnet->location_id !== $locationId || $subnet->cidr !== $data['cidr'])) {
            if ($subnet->parent_subnet_id || $subnet->childSubnets()->exists()) {
                throw ValidationException::withMessages(['form.cidr' => __('Ce réseau fait partie d’un découpage. Sa plage et son emplacement sont fixes.')]);
            }
            if ($subnet->scanPending()) {
                throw ValidationException::withMessages(['form.cidr' => __('Attendez la fin de la découverte avant de déplacer ou modifier cette plage.')]);
            }
            $subnet->forceFill([
                'scan_token' => null, 'scan_status' => null, 'scan_requested_at' => null, 'scanned_at' => null,
                'scan_targets' => null, 'scan_responded' => null,
            ]);
            $subnet->addresses()->update(['last_seen_at' => null, 'last_probe_at' => null, 'resolved_hostname' => null, 'mac_address' => null, 'last_probe_responded' => null]);
        }
    }

    private function validateVlanLocation(Vlan $vlan, ?int $locationId): void
    {
        if ($locationId !== null && $vlan->subnets()->where(function (Builder $query) use ($locationId): void {
            $query->whereNull('location_id')->orWhereNotIn('location_id', Location::findOrFail($locationId)->descendantsAndSelf());
        })->exists()) {
            throw ValidationException::withMessages(['form.location_id' => __('Des sous-réseaux liés se trouvent en dehors de cet emplacement.')]);
        }
    }

    private function query(): Builder
    {
        return match ($this->section) {
            'subnets' => Subnet::query(),
            'addresses' => IpAddress::query(),
            'vlans' => Vlan::query(),
            'locations' => Location::query(),
        };
    }

    public function render(): View
    {
        $search = mb_substr(trim($this->search), 0, 100);
        $query = $this->query();
        $selectedSubnet = $this->section === 'addresses' && ctype_digit($this->subnet) ? Subnet::find($this->subnet) : null;
        $spaceSubnet = $this->section === 'subnets' && ctype_digit($this->space) ? Subnet::find($this->space) : null;
        $location = ctype_digit($this->location) ? Location::find($this->location) : null;
        if ($location && ! $selectedSubnet && $this->section !== 'locations') {
            $ids = $location->descendantsAndSelf();
            if ($this->section === 'addresses') {
                $query->whereHas('subnet', fn (Builder $query) => $query->whereIn('location_id', $ids));
            } else {
                $query->whereIn('location_id', $ids);
            }
        }

        if ($this->section === 'subnets') {
            $query->with(['vlan', 'location.parent.parent', 'childSubnets'])->withCount([
                'addresses as assigned' => fn (Builder $query) => $query->whereIn('status', ['used', 'reserved']),
            ])
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('name', '%'.$search.'%')->orWhereLike('cidr', '%'.$search.'%')))
                ->orderBy('name');
        } elseif ($this->section === 'addresses') {
            $query->with('subnet.location.parent.parent')
                ->when($selectedSubnet, fn (Builder $query) => $query->where('subnet_id', $selectedSubnet->id))
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('address', '%'.$search.'%')->orWhereLike('hostname', '%'.$search.'%')->orWhereLike('resolved_hostname', '%'.$search.'%')->orWhereLike('mac_address', '%'.(MacAddress::normalize($search) ?? $search).'%')))
                ->orderBy('address_key');
        } elseif ($this->section === 'vlans') {
            $query->with('location.parent.parent')->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('name', '%'.$search.'%')
                ->when(ctype_digit($search) && (int) $search <= 4094, fn ($query) => $query->orWhere('number', (int) $search))))
                ->orderBy('number');
        } else {
            $query->with('parent.parent')->withCount(['subnets', 'vlans'])
                ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.$search.'%'))
                ->orderBy('kind')->orderBy('name');
        }

        $records = $query->orderBy('id')->paginate(15);
        $children = $selectedSubnet?->childSubnets()->get() ?? collect();
        $availability = app(AddressAvailability::class);

        return view('livewire.inventory', [
            'records' => $records,
            'displayRows' => $selectedSubnet && $search === '' ? $availability->rows($selectedSubnet, $records, $children) : $records,
            'selectedSubnet' => $selectedSubnet,
            'addressGrid' => $selectedSubnet && $search === '' ? $availability->grid($selectedSubnet, $children) : [],
            'delegatedCount' => $children->count(),
            'addressSubnets' => $this->section === 'addresses' ? Subnet::with('location.parent.parent')->orderBy('name')->get() : collect(),
            'spaceSubnet' => $spaceSubnet,
            'spaceMap' => $spaceSubnet ? app(SubnetSpace::class)->map($spaceSubnet) : [],

            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
            'scanSubnet' => $this->scanId ? Subnet::find($this->scanId) : null,
            'options' => $this->editing ? match ($this->section) {
                'subnets' => Vlan::with('location.parent.parent')->orderBy('number')->get(),

                default => collect(),
            } : collect(),
        ])->layout('components.layouts.app', ['title' => __(self::SECTIONS[$this->section][0])]);
    }
}
